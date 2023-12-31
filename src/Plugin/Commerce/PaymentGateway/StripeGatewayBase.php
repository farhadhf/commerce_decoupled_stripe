<?php

namespace Drupal\commerce_decoupled_stripe\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Entity\PaymentMethodInterface;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\PaymentMethodTypeManager;
use Drupal\commerce_payment\PaymentTypeManager;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OnsitePaymentGatewayBase;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsAuthorizationsInterface;
use Drupal\commerce_price\Calculator;
use Drupal\commerce_price\Price;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Stripe\Customer;
use Stripe\PaymentIntent;
use Stripe\SetupIntent;
use Stripe\Stripe;

/**
 * Provides base methods for Decoupled Stripe gateways.
 */
abstract class StripeGatewayBase extends OnsitePaymentGatewayBase implements SupportsAuthorizationsInterface {

  /**
   * List of zero decimal currencies to convert amount correctly for Stripe API.
   * https://stripe.com/docs/currencies#zero-decimal
   */
  const ZERO_DECIMAL_CURRENCIES = ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, PaymentTypeManager $payment_type_manager, PaymentMethodTypeManager $payment_method_type_manager, TimeInterface $time) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager, $payment_type_manager, $payment_method_type_manager, $time);

    $this->init();
  }

  /**
   * Re-initializes the SDK after the plugin is unserialized.
   */
  public function __wakeup() {
    $this->init();
  }

  /**
   * Initializes the SDK.
   */
  protected function init() {
    Stripe::setAppInfo('Decoupled Stripe for Drupal Commerce');
    Stripe::setApiKey($this->configuration['secret_key']);
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'publishable_key' => '',
      'secret_key' => '',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);
    $form['publishable_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Publishable Key'),
      '#default_value' => $this->configuration['publishable_key'],
      '#required' => TRUE,
    ];
    $form['secret_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Secret Key'),
      '#default_value' => $this->configuration['secret_key'],
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
      $this->configuration['publishable_key'] = $values['publishable_key'];
      $this->configuration['secret_key'] = $values['secret_key'];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function createPaymentMethod(PaymentMethodInterface $payment_method, array $payment_details) {
    $owner = $payment_method->getOwner();
    $email = $owner->getEmail();
    // If anonymous do not create customer.
    if (!$email) {
      return;
    }

    $stripe_customer_id = NULL;

    $customer_name = '';
    $customer_address = [];
    if ($billing_address = $payment_method->getBillingProfile()) {
      $billing_address = $payment_method->getBillingProfile()->get('address')->first();
      if (!empty($billing_address)) {
        $customer_name = trim($billing_address->getGivenName() . ' ' . $billing_address->getFamilyName());
        if (!empty($billing_address->getAddressLine1())) {
          $customer_address = [
            'line1' => $billing_address->getAddressLine1(),
            'line2' => $billing_address->getAddressLine2(),
            'city' => $billing_address->getLocality(),
            'country' => $billing_address->getCountryCode(),
            'state' => $billing_address->getAdministrativeArea(),
          ];
        }
        if ($billing_address->getPostalCode()) {
          $customer_address['postal_code'] = $billing_address->getPostalCode();
        }
      }
    }

    $customer_payload = [
      'name' => $customer_name,
      'metadata' => [
        'commerce_decoupled_stripe' => 1,
      ],
    ];
    if (!empty($customer_address)) {
      $customer_payload['address'] = $customer_address;
    }

    // Try to find existing Stripe customer.
    try {
      $customer = Customer::all(['limit' => 1, 'email' => $email]);
    }
    catch (\Exception $e) {
      // The payment can go ahead even if Stripe fetching API throws an error.
      watchdog_exception('commerce_decoupled_stripe', $e);
    }
    if (!empty($customer) && !empty($customer->data)) {
      $stripe_customer_id = $customer->data[0]->id;
      Customer::update($stripe_customer_id, $customer_payload);
    }
    else {
      $customer_payload['email'] = $email;
      // Create a new customer in Stripe.
      $customer = Customer::create($customer_payload);
      if ($customer_address) {
        $customer['address'] = $customer_address;
      }
      $stripe_customer_id = $customer->id;
    }

    // Current implementation doesn't support reusable Commerce payment methods.
    $payment_method->setReusable(FALSE);

    if (empty($payment_details['card_type'])) {
      // Card Type should be set to prevent error in
      // \Drupal\commerce_payment\Plugin\Commerce\PaymentMethodType::buildLabel()
      // This gets overridden later when the payment is being captured.
      // @See https://www.drupal.org/project/commerce_decoupled_checkout/issues/3114285
      $payment_details['card_type'] = 'visa';
    }
    $payment_method->set('card_type', $payment_details['card_type']);
    $payment_method->save();

    // Save in payment method object for further processing.
    $payment_method->stripe_customer_id = $stripe_customer_id;
  }

  /**
   * {@inheritdoc}
   */
  public function deletePaymentMethod(PaymentMethodInterface $payment_method) {
    // Delete the local entity.
    $payment_method->delete();
  }

  /**
   * {@inheritdoc}
   */
  public function voidPayment(PaymentInterface $payment) {
    $payment_method = $payment->getPaymentMethod();
    $intent_id = $payment_method->getRemoteId();
    if ($payment->getPaymentGateway()->getPluginId() == 'decoupled_stripe_recurring') {
      $intent = SetupIntent::retrieve($intent_id);
    }
    else {
      $intent = PaymentIntent::retrieve($intent_id);
    }

    // Same voiding check as in drupal/stripe module.
    $statuses_to_void = [
      'requires_payment_method',
      'requires_capture',
      'requires_confirmation',
      'requires_action',
    ];
    if (!in_array($intent->status, $statuses_to_void)) {
      throw new PaymentGatewayException('The PaymentIntent cannot be voided.');
    }
    $intent->cancel();
    $payment->setRemoteId($intent_id);
    $payment->setState('authorization_voided');
    $payment->save();
  }

  /**
   * Convert amount to minor units of specific currency according to Stripe.
   *
   * Stripe currencies could be different to Drupal currencies, so use constant lists from:
   * https://stripe.com/docs/currencies#zero-decimal
   */
  public function toMinorUnits(Price $amount) {
    $fraction_digits = in_array($amount->getCurrencyCode(), self::ZERO_DECIMAL_CURRENCIES) ? 0 : 2;
    $number = $amount->getNumber();
    if ($fraction_digits > 0) {
      $number = Calculator::multiply($number, pow(10, $fraction_digits));
    }

    return round($number, 0);
  }

}
