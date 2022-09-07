<?php

namespace Drupal\commerce_decoupled_stripe\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Exception\DeclineException;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsAuthorizationsInterface;
use Drupal\commerce_price\Price;
use Drupal\Core\Form\FormStateInterface;
use Stripe\PaymentIntent;

/**
 * Provides Decoupled Stripe gateway for one-off payments.
 *
 * @CommercePaymentGateway(
 *   id = "decoupled_stripe",
 *   label = "Decoupled Stripe",
 *   display_label = "Decoupled Stripe",
 *   payment_method_types = {"credit_card"}
 * )
 */
class StripeGateway extends StripeGatewayBase implements SupportsAuthorizationsInterface {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
        'enable_receipt_email' => FALSE,
      ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['enable_receipt_email'] = [
      '#type' => 'checkbox',
      '#title' => t('Force enable Stripe email receipts on successful payment.'),
      '#default_value' => $this->configuration['enable_receipt_email'],
    ];

    return $form;
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

    $order_id = $payment->getOrderId();
    $order = $payment->getOrder();
    $amount = $this->toMinorUnits($payment->getAmount());

    // Create payment intent in Stripe and prepare client secret to process
    // this intent on frontend.
    $intent_array = [
      'amount' => $amount,
      'currency' => strtolower($order->getTotalPrice()->getCurrencyCode()),
      'payment_method_types' => ['card'],
      'metadata' => [
        'order_id' => $order_id,
        'payment_gateway' => $payment->getPaymentGateway()->label(),
      ],
      // Let Stripe to capture funds automatically.
      'capture_method' => 'automatic',
    ];

    if (!empty($this->configuration['enable_receipt_email'])) {
      $intent_array['receipt_email'] = $order->getEmail();
    }

    if (!empty($payment_method->stripe_customer_id)) {
      // If customer is not provided, Stripe will create new one.
      $intent_array['customer'] = $payment_method->stripe_customer_id;
    }
    try {
      $intent = PaymentIntent::create($intent_array);
    }
    catch (\Exception $e) {
      watchdog_exception('commerce_decoupled_stripe', $e);
      throw new DeclineException('Server could not create payment intent.');
    }

    // Save client secret on payment level to make it available
    // via /payment/create endpoint.
    $payment->setRemoteId($intent->client_secret);
    $payment->save();
    // Save intent id on payment method level for backend use only.
    $payment_method->setRemoteId($intent->id);
    // Set fake card type to avoid issue during viewing order with undefined card type.
    // Card type will be overwritten with real value if payment will be success.
    $payment_method->card_type = 'visa';
    $payment_method->save();
  }

  /**
   * {@inheritdoc}
   */
  public function capturePayment(PaymentInterface $payment, Price $amount = NULL) {
    $payment_method = $payment->getPaymentMethod();
    $this->assertPaymentMethod($payment_method);

    // Load the intent and ensure it's successful.
    $intent = PaymentIntent::retrieve($payment_method->getRemoteId());

    if (
      $intent->status == PaymentIntent::STATUS_CANCELED
      || $intent->status == PaymentIntent::STATUS_REQUIRES_PAYMENT_METHOD
    ) {
      $payment->setState('authorization_voided');
      $payment->save();

      throw new DeclineException('The payment has been cancelled.');
    }
    elseif ($intent->status !== PaymentIntent::STATUS_SUCCEEDED) {

      // If payment is not yet successful in Stripe, we give it a day
      // to complete. All sessions older than 24h are automatically expired
      // by Stripe and therefore can't be completed, so we cancel them.
      $one_day_ago = strtotime('-1 day');
      if ($payment_method->getCreatedTime() < $one_day_ago) {
        $payment->setState('authorization_expired');
        $payment->save();

        throw new DeclineException('The payment has expired.');
      }
      else {
        // Change state to authorization so that we demonstrate that
        // the auth / payment process is still ongoing.
        $payment->setState('authorization');
        $payment->save();

        throw new DeclineException('The payment is not (yet) succeeded.');
      }
    }
    // An edge case.
    if (empty($intent->charges->data)) {
      $payment->setState('authorization');
      $payment->save();
      throw new DeclineException("Couldn't load payment data.");
    }

    // Collect card details.
    if (!empty($intent->charges->data[0]->payment_method_details->card)) {
      $remote_payment_method = $intent->charges->data[0]->payment_method_details->card;
      $payment_method->card_type = $remote_payment_method['brand'];
      $payment_method->card_number = $remote_payment_method['last4'];
      $payment_method->card_exp_month = $remote_payment_method['exp_month'];
      $payment_method->card_exp_year = $remote_payment_method['exp_year'];
    }

    // Update payment method remote id.
    $payment_method->setRemoteId($intent->charges->data[0]->id);
    $payment_method->save();

    // Finalize the payment.
    $payment->setState('completed');
    $payment->setRemoteId($intent->id);
    $payment->save();
  }
}
