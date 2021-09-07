<?php

namespace Drupal\commerce_decoupled_stripe\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Exception\DeclineException;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsAuthorizationsInterface;
use Drupal\commerce_price\Price;
use Drupal\Core\Form\FormStateInterface;
use Stripe\PaymentMethod;
use Stripe\SetupIntent;
use Stripe\Subscription;
use Stripe\Plan;

/**
 * Provides Decoupled Stripe gateway for recurring (monthly) payments.
 *
 * @CommercePaymentGateway(
 *   id = "decoupled_stripe_recurring",
 *   label = "Decoupled Stripe Recurring",
 *   display_label = "Decoupled Stripe Recurring",
 *   payment_method_types = {"credit_card"}
 * )
 */
class StripeRecurringGateway extends StripeGatewayBase implements SupportsAuthorizationsInterface {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
        'recurring_start_day' => '2',
        'recurring_plan_id' => 'commerce_decoupled_stripe_monthly',
        'recurring_plan_name' => 'Monthly donation',
      ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['recurring_start_day'] = [
      '#type' => 'select',
      '#title' => t('Select when to start billing recurring payments'),
      '#required' => TRUE,
      '#options' => array_combine(range(1, 30), range(1, 30)),
      '#default_value' => $this->configuration['recurring_start_day'],
    ];

    $form['recurring_plan_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Plan ID in Stripe'),
      '#description' => $this->t('The module will create a base product and plan in Stripe to associate recurring payments to it. Leaving default value is recommended if you are not sure.'),
      '#default_value' => $this->configuration['recurring_plan_id'],
      '#required' => TRUE,
    ];

    $form['recurring_plan_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Plan name in Stripe'),
      '#description' => $this->t('Human readable name of Stripe product and plan.'),
      '#default_value' => $this->configuration['recurring_plan_name'],
      '#required' => TRUE,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);

    if (!$form_state->getErrors()) {
      $values = $form_state->getValue($form['#parents']);
      $this->configuration['recurring_start_day'] = $values['recurring_start_day'];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function createPayment(PaymentInterface $payment, $capture = TRUE) {
    $this->assertPaymentState($payment, ['new']);
    $payment_method = $payment->getPaymentMethod();
    $this->assertPaymentMethod($payment_method);

    // Decoupled Stripe assumes that capture will happen after user confirms
    // transaction on client side.
    if (!empty($capture)) {
      return;
    }

    // Ensure Stripe customer has been created / fetched.
    if (empty($payment_method->stripe_customer_id)) {
      throw new DeclineException('Server could not find a customer.');
    }

    // Create setup intent in Stripe and prepare client secret to process
    // this intent on frontend.
    $intent_array = [
      'customer' => $payment_method->stripe_customer_id,
      'payment_method_types' => ['card'],
      'metadata' => [
        'order_id' => $payment->getOrderId(),
        'payment_gateway' => $payment->getPaymentGateway()->label(),
      ],
      'usage' => 'off_session',
    ];

    $intent = SetupIntent::create($intent_array);

    // Save client secret on payment level to make it available
    // via /payment/create endpoint.
    $payment->setRemoteId($intent->client_secret);
    $payment->save();
    // Save intent id on payment method level for backend use only.
    $payment_method->setRemoteId($intent->id);
    $payment_method->save();
  }

  /**
   * {@inheritdoc}
   */
  public function capturePayment(PaymentInterface $payment, Price $amount = NULL) {
    $payment_method = $payment->getPaymentMethod();
    $this->assertPaymentMethod($payment_method);

    $intent = SetupIntent::retrieve($payment_method->getRemoteId());
    if ($intent->status == SetupIntent::STATUS_CANCELED) {
      $payment->setState('authorization_voided');
      $payment->save();

      throw new DeclineException('The recurring payment has been cancelled.');
    }
    elseif ($intent->status !== SetupIntent::STATUS_SUCCEEDED) {

      // If payment is not yet successful in Stripe, we give it a day
      // to complete. All sessions older than 24h are automatically expired
      // by Stripe and therefore can't be completed, so we cancel them.
      $one_day_ago = strtotime('-1 day');
      if ($payment_method->getCreatedTime() < $one_day_ago) {
        $payment->setState('authorization_expired');
        $payment->save();

        throw new DeclineException('The recurring payment has expired.');
      }
      else {
        // Change state to authorization so that we demonstrate that
        // the auth / payment process is still ongoing.
        $payment->setState('authorization');
        $payment->save();

        throw new DeclineException('The recurring payment is not (yet) succeeded.');
      }
    }

    // Associate payment method with current customer in Stripe.
    $remote_payment_method = PaymentMethod::retrieve($intent->payment_method);
    $remote_payment_method->attach(['customer' => $intent->customer]);

    // Create a subscription.
    $plan = $this->getPlan($payment->getAmount()->getCurrencyCode());
    $subscription = Subscription::create([
      'customer' => $intent->customer,
      'trial_end' => $this->getStartDateTimestamp(),
      'items' => [
        [
          'plan' => $plan->id,
          // Example quantity calculation:
          // £20 total / £1 plan base price = 20 quantity.
          'quantity' => intval($payment->getAmount()->getNumber()),
        ],
      ],
      'metadata' => [
        'commerce_decoupled_stripe' => 1,
        'order_id' => $payment->getOrderId(),
        'drupal_payment_gateway' => $payment->getPaymentGateway()->label(),
      ],
      // Set payment method explicitly, otherwise Stripe may use another card
      // attached to this customer previously.
      'default_payment_method' => $intent->payment_method,
    ]);

    // Collect card details.
    if (!empty($remote_payment_method->card)) {
      $payment_method->card_type = $remote_payment_method->card['brand'];
      $payment_method->card_number = $remote_payment_method->card['last4'];
      $payment_method->card_exp_month = $remote_payment_method->card['exp_month'];
      $payment_method->card_exp_year = $remote_payment_method->card['exp_year'];
    }

    // Update payment method remote id.
    $payment_method->setRemoteId($subscription->id);
    $payment_method->save();

    // Finalize the payment.
    $payment->setState('completed');
    $payment->setRemoteId($intent->id);
    $payment->save();
  }

  /**
   * Fetches or creates a new Stripe plan for recurring payments.
   */
  protected function getPlan($currency) {
    $remote_id = $this->configuration['recurring_plan_id'];
    try {
      $plan = Plan::retrieve($remote_id);
    }
    catch (\Exception $e) {
      $plan = Plan::create([
        'id' => $remote_id,
        "amount" => 100,
        "currency" => strtolower($currency),
        'billing_scheme' => 'per_unit',
        "interval" => "month",
        "product" => [
          'id' => $remote_id,
          "name" => $this->configuration['recurring_plan_name'],
          'metadata' => [
            'commerce_decoupled_stripe' => 1,
          ],
        ],
      ]);
    }

    return $plan;
  }

  /**
   * Helper to calculate next billing date.
   */
  public function getStartDateTimestamp() {
    $datetime = new \DateTime();
    $recurring_start_day = $this->configuration['recurring_start_day'];

    $day_of_month = $datetime->format('j');
    // If the current day of the month is leaser than day when to start
    // billing, then we need to set the payment for this month, otherwise
    // it will be the next month.
    if ($day_of_month >= $recurring_start_day) {
      $datetime->modify('+1 month');
    }

    // This is needed to prevent cases like adding 1 month to 31rd of
    // October results in 1st of December instead of 30th of November.
    // See https://stackoverflow.com/questions/5760262/php-adding-months-to-a-date-while-not-exceeding-the-last-day-of-the-month
    // for example of the issue.
    $end_day_of_month = $datetime->format('j');
    if ($day_of_month != $end_day_of_month) {
      // The day of the month isn't the same anymore, so we correct the date.
      $datetime->modify('last day of last month');
    }

    $date_string = $datetime->format('Y') . '-' . $datetime->format('m') . '-' . $recurring_start_day . ' 11:00';

    return strtotime($date_string);
  }

}
