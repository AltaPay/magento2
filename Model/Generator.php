<?php
/**
 * Valitor Module for Magento 2.x.
 *
 * Copyright © 2020 Valitor. All rights reserved.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace SDM\Valitor\Model;

use Valitor\Api\Ecommerce\Callback;
use Valitor\Api\Ecommerce\PaymentRequest;
use Valitor\Api\Test\TestAuthentication;
use Valitor\Exceptions\ClientException;
use Valitor\Exceptions\ResponseHeaderException;
use Valitor\Exceptions\ResponseMessageException;
use Valitor\Request\Config;
use Valitor\Response\CallbackResponse;
use Magento\Checkout\Model\Session;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Logger\Monolog;
use Magento\Framework\UrlInterface;
use Magento\Quote\Model\Quote;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Framework\DB\TransactionFactory;
use Magento\Sales\Model\Service\InvoiceService;
use SDM\Valitor\Helper\Data;
use SDM\Valitor\Helper\Config as storeConfig;
use SDM\Valitor\Model\Handler\OrderLinesHandler;
use SDM\Valitor\Model\Handler\CustomerHandler;
use SDM\Valitor\Model\Handler\PriceHandler;
use SDM\Valitor\Model\Handler\DiscountHandler;
use SDM\Valitor\Model\Handler\CreatePaymentHandler;
use SDM\Valitor\Model\TokenFactory;
use Magento\Sales\Model\OrderFactory;

/**
 * Class Generator
 * Handle the create payment related functionality.
 */
class Generator
{
    /**
     * @var Helper Data
     */
    private $helper;
    /**
     * @var Quote
     */
    private $quote;
    /**
     * @var UrlInterface
     */
    private $urlInterface;
    /**
     * @var Session
     */
    private $checkoutSession;
    /**
     * @var Http
     */
    private $request;
    /**
     * @var Order
     */
    private $order;
    /**
     * @var OrderSender
     */
    private $orderSender;
    /**
     * @var SystemConfig
     */
    private $systemConfig;
    /**
     * @var Monolog
     */
    private $_logger;
    /**
     * @var TransactionFactory
     */
    private $transactionFactory;
    /**
     * @var InvoiceService
     */
    private $invoiceService;
    /**
     * @var OrderLinesHandler
     */
    private $orderLines;
    /**
     * @var Helper Config
     */
    private $storeConfig;
    /**
     * @var CustomerHandler
     */
    private $customerHandler;
    /**
     * @var PriceHandler
     */
    private $priceHandler;
    /**
     * @var DiscountHandler
     */
    private $discountHandler;
    /**
     * @var CreatePaymentHandler
     */
    private $paymentHandler;
    /**
     * @var TokenFactory
     */
    private $dataToken;
    /**
     * @var OrderFactory
     */
    private $orderFactory;

    /**
     *
     * @param Quote                $quote
     * @param UrlInterface         $urlInterface
     * @param Session              $checkoutSession
     * @param Http                 $request
     * @param Order                $order
     * @param OrderSender          $orderSender
     * @param SystemConfig         $systemConfig
     * @param Monolog              $_logger
     * @param OrderFactory         $orderFactory
     * @param InvoiceService       $invoiceService
     * @param TransactionFactory   $transactionFactory
     * @param Data                 $helper
     * @param storeConfig          $storeConfig
     * @param OrderLinesHandler    $orderLines
     * @param CustomerHandler      $customerHandler
     * @param PriceHandler         $priceHandler
     * @param DiscountHandler      $discountHandler
     * @param CreatePaymentHandler $paymentHandler
     * @param TokenFactory         $dataToken
     */
    public function __construct(
        Quote $quote,
        UrlInterface $urlInterface,
        Session $checkoutSession,
        Http $request,
        Order $order,
        OrderSender $orderSender,
        SystemConfig $systemConfig,
        Monolog $_logger,
        OrderFactory $orderFactory,
        InvoiceService $invoiceService,
        TransactionFactory $transactionFactory,
        Data $helper,
        storeConfig $storeConfig,
        OrderLinesHandler $orderLines,
        CustomerHandler $customerHandler,
        PriceHandler $priceHandler,
        DiscountHandler $discountHandler,
        CreatePaymentHandler $paymentHandler,
        TokenFactory $dataToken
    ) {
        $this->quote              = $quote;
        $this->urlInterface       = $urlInterface;
        $this->checkoutSession    = $checkoutSession;
        $this->request            = $request;
        $this->order              = $order;
        $this->orderSender        = $orderSender;
        $this->systemConfig       = $systemConfig;
        $this->_logger            = $_logger;
        $this->invoiceService     = $invoiceService;
        $this->transactionFactory = $transactionFactory;
        $this->orderFactory       = $orderFactory;
        $this->helper             = $helper;
        $this->storeConfig        = $storeConfig;
        $this->orderLines         = $orderLines;
        $this->customerHandler    = $customerHandler;
        $this->priceHandler       = $priceHandler;
        $this->discountHandler    = $discountHandler;
        $this->paymentHandler     = $paymentHandler;
        $this->dataToken          = $dataToken;
    }

    /**
     * createRequest to valitor
     *
     * @param int    $terminalId
     * @param string $orderId
     *
     * @return array
     */
    public function createRequest($terminalId, $orderId)
    {
        $order = $this->order->load($orderId);
        $storePriceIncTax = $this->storeConfig->storePriceIncTax();
        if ($order->getId()) {
            $couponCode       = $order->getDiscountDescription();
            $couponCodeAmount = $order->getDiscountAmount();
            $discountAllItems = $this->discountHandler->allItemsHaveDiscount($order->getAllVisibleItems());
            $orderLines       = $this->itemOrderLines($couponCodeAmount, $order, $discountAllItems);
            if ($this->orderLines->sendShipment($order) && !empty($order->getShippingMethod(true))) {
                $orderLines[] = $this->orderLines->handleShipping($storePriceIncTax, $order, $discountAllItems, true);
            }
            if ($discountAllItems && abs($couponCodeAmount) > 0) {
                $orderLines[] = $this->orderLines->discountOrderLine($couponCodeAmount, $couponCode);
            }
            $request = $this->preparePaymentRequest($order, $orderLines, $orderId, $terminalId);
            if ($request) {
                return $this->sendPaymentRequest($order, $request);
            }
        }

        return $this->restoreOrderAndReturnError($order);
    }

    /**
     * @param $orderId
     *
     * @throws AlreadyExistsException
     */
    public function restoreOrderFromOrderId($orderId)
    {
        $order = $this->orderLoader->getOrderByOrderIncrementId($orderId);
        if ($order->getId()) {
            $quote = $this->quote->loadByIdWithoutStore($order->getQuoteId());
            $quote->setIsActive(1)->setReservedOrderId(null);
            $quote->getResource()->save($quote);
            $this->checkoutSession->replaceQuote($quote);
        }
    }

    /**
     * @param      $order
     * @param bool $requireCapture
     */
    public function createInvoice($order, $requireCapture = false)
    {
        if (filter_var($requireCapture, FILTER_VALIDATE_BOOLEAN) === true) {
            $captureType = \Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE;
        } else {
            $captureType = \Magento\Sales\Model\Order\Invoice::CAPTURE_OFFLINE;
        }

        if (!$order->getInvoiceCollection()->count()) {
            $invoice = $this->invoiceService->prepareInvoice($order);
            $invoice->setRequestedCaptureCase($captureType);
            $invoice->register();
            $invoice->getOrder()->setCustomerNoteNotify(false);
            $invoice->getOrder()->setIsInProcess(true);
            $transaction = $this->transactionFactory->create()->addObject($invoice)->addObject($invoice->getOrder());
            $transaction->save();
        }
    }

    /**
     * @param RequestInterface $request
     *
     * @return bool
     * @throws \Exception
     */
    public function restoreOrderFromRequest(RequestInterface $request)
    {
        $callback = new Callback($request->getPostValue());
        $response = $callback->call();
        if ($response) {
            $order = $this->loadOrderFromCallback($response);
            if ($order->getQuoteId()) {
                if ($quote = $this->quote->loadByIdWithoutStore($order->getQuoteId())) {
                    $quote->setIsActive(1)->setReservedOrderId(null)->save();
                    $this->checkoutSession->replaceQuote($quote);

                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param RequestInterface $request
     */
    public function handleNotificationAction(RequestInterface $request)
    {
        $this->completeCheckout(__(ConstantConfig::NOTIFICATION_CALLBACK), $request);
    }

    /**
     * @param RequestInterface $request
     * @param                  $responseStatus
     */
    public function handleCancelStatusAction(RequestInterface $request, $responseStatus)
    {
        $responseComment = __(ConstantConfig::CONSUMER_CANCEL_PAYMENT);
        if ($responseStatus != 'cancelled') {
            $responseComment = __(ConstantConfig::UNKNOWN_PAYMENT_STATUS_MERCHANT);
        }
        $historyComment = __(ConstantConfig::CANCELLED) . '|' . $responseComment;
        //TODO: fetch the MerchantErrorMessage and use it as historyComment
        $callback = new Callback($request->getPostValue());
        $response = $callback->call();
        if ($response) {
            $order = $this->loadOrderFromCallback($response);
            //check if order status set in configuration
            $statusKey         = Order::STATE_CANCELED;
            $storeCode         = $order->getStore()->getCode();
            $storeScope        = \Magento\Store\Model\ScopeInterface::SCOPE_STORE;
            $orderStatusCancel = $this->systemConfig->getStatusConfig('cancel', $storeScope, $storeCode);

            if ($orderStatusCancel) {
                $statusKey = $orderStatusCancel;
            }
            $this->handleOrderStateAction($request, Order::STATE_CANCELED, $statusKey, $historyComment);
        }
    }

    /**
     * @param RequestInterface $request
     * @param                  $msg
     * @param                  $merchantErrorMsg
     * @param                  $responseStatus
     *
     * @throws \Exception
     */
    public function handleFailedStatusAction(RequestInterface $request, $msg, $merchantErrorMsg, $responseStatus)
    {
        $historyComment = $responseStatus . '|' . $msg;
        if (!empty($merchantErrorMsg)) {
            $historyComment = $historyComment . '|' . $merchantErrorMsg;
        }
        $transInfo = null;
        $callback  = new Callback($request->getPostValue());
        $response  = $callback->call();
        if ($response) {
            $order     = $this->loadOrderFromCallback($response);
            $transInfo = $this->getTransactionInfoFromResponse($response);
            //check if order status set in configuration
            $statusKey         = Order::STATE_CANCELED;
            $storeCode         = $order->getStore()->getCode();
            $storeScope        = \Magento\Store\Model\ScopeInterface::SCOPE_STORE;
            $orderStatusCancel = $this->systemConfig->getStatusConfig('cancel', $storeScope, $storeCode);

            if ($orderStatusCancel) {
                $statusKey = $orderStatusCancel;
            }
            $this->handleOrderStateAction($request, Order::STATE_CANCELED, $statusKey, $historyComment, $transInfo);
        }
    }

    /**
     * @param CallbackResponse $response
     *
     * @return Order
     */
    private function loadOrderFromCallback(CallbackResponse $response)
    {
        return $this->orderFactory->create()->loadByIncrementId($response->shopOrderId);
    }

    /**
     * @param RequestInterface $request
     * @param string           $orderState
     * @param string           $orderStatus
     * @param string           $historyComment
     * @param null             $transactionInfo
     *
     * @return bool
     * @throws AlreadyExistsException
     */
    public function handleOrderStateAction(
        RequestInterface $request,
        $orderState = Order::STATE_NEW,
        $orderStatus = Order::STATE_NEW,
        $historyComment = "Order state changed",
        $transactionInfo = null
    ) {
        $callback = new Callback($request->getPostValue());
        $response = $callback->call();
        if ($response) {
            $order = $this->loadOrderFromCallback($response);
            $order->setState($orderState);
            $order->setIsNotified(false);
            if ($transactionInfo !== null) {
                $order->addStatusHistoryComment($transactionInfo);
            }
            $order->addStatusHistoryComment($historyComment, $orderStatus);
            $order->getResource()->save($order);

            return true;
        }

        return false;
    }

    /**
     * @param RequestInterface $request
     */
    public function handleOkAction(RequestInterface $request)
    {
        $this->completeCheckout(__(ConstantConfig::OK_CALLBACK), $request);
    }

    /**
     * @param                  $comment
     * @param RequestInterface $request
     *
     * @throws \Exception
     */
    private function completeCheckout($comment, RequestInterface $request)
    {
        $callback       = new Callback($request->getParams());
        $response       = $callback->call();
        $paymentStatus  = $response->type;
        $requireCapture = $response->requireCapture;
        if ($response) {
            $order      = $this->loadOrderFromCallback($response);
            $storeScope = \Magento\Store\Model\ScopeInterface::SCOPE_STORE;
            $storeCode  = $order->getStore()->getCode();
            if ($order->getId()) {
                $cardType = '';
                $expires  = '';
                if (isset($response->Transactions[0])) {
                    $transaction = $response->Transactions[0];
                    if (isset($transaction->CreditCardExpiry->Month) && isset($transaction->CreditCardExpiry->Year)) {
                        $expires = $transaction->CreditCardExpiry->Month . '/' . $transaction->CreditCardExpiry->Year;
                    }
                    if (isset($transaction->PaymentSchemeName)) {
                        $cardType = $transaction->PaymentSchemeName;
                    }
                }
                $payment = $order->getPayment();
                $payment->setPaymentId($response->paymentId);
                $payment->setLastTransId($response->transactionId);
                $payment->setCcTransId($response->creditCardToken);
                $payment->setAdditionalInformation('cc_token', $response->creditCardToken);
                $payment->setAdditionalInformation('masked_credit_card', $response->maskedCreditCard);
                $payment->setAdditionalInformation('expires', $expires);
                $payment->setAdditionalInformation('card_type', $cardType);
                $payment->save();
                //send order confirmation email
                $this->sendOrderConfirmationEmail($comment, $order);
                //unset redirect if success
                $this->checkoutSession->unsValitorCustomerRedirect();

                $orderStatusAfterPayment = $this->systemConfig->getStatusConfig('process', $storeScope, $storeCode);
                $orderStatusCapture      = $this->systemConfig->getStatusConfig('autocapture', $storeScope, $storeCode);
                $setOrderStatus          = true;
                $orderState              = Order::STATE_PROCESSING;
                $statusKey               = 'process';

                if ($this->isCaptured($response, $storeCode, $storeScope)) {
                    if ($orderStatusCapture == "complete") {
                        if ($this->orderLines->sendShipment($order)) {
                            $orderState = Order::STATE_COMPLETE;
                            $statusKey  = 'autocapture';
                            $order->addStatusHistoryComment(__(ConstantConfig::PAYMENT_COMPLETE));
                        } else {
                            $setOrderStatus = false;
                            $order->addStatusToHistory($orderStatusCapture, ConstantConfig::PAYMENT_COMPLETE, false);
                        }
                    }
                } else {
                    if ($orderStatusAfterPayment) {
                        $orderState = $orderStatusAfterPayment;
                    }
                }
                if ($setOrderStatus) {
                    $this->paymentHandler->setCustomOrderStatus($order, $orderState, $statusKey);
                }
                $order->addStatusHistoryComment($comment);
                $order->addStatusHistoryComment($this->getTransactionInfoFromResponse($response));
                $order->setIsNotified(false);
                $order->getResource()->save($order);

                if (strtolower($paymentStatus) == 'paymentandcapture') {
                    $this->createInvoice($order, $requireCapture);
                }
            }
        }
    }

    /**
     * @param $response
     *
     * @return string
     */
    private function getTransactionInfoFromResponse($response)
    {
        return sprintf(
            "Transaction ID: %s - Payment ID: %s - Credit card token: %s",
            $response->transactionId,
            $response->paymentId,
            $response->creditCardToken
        );
    }

    /**
     * @return Config
     */
    private function setConfig()
    {
        $config = new Config();
        $config->setCallbackOk($this->urlInterface->getDirectUrl(ConstantConfig::VALITOR_OK));
        $config->setCallbackFail($this->urlInterface->getDirectUrl(ConstantConfig::VALITOR_FAIL));
        $config->setCallbackRedirect($this->urlInterface->getDirectUrl(ConstantConfig::VALITOR_REDIRECT));
        $config->setCallbackOpen($this->urlInterface->getDirectUrl(ConstantConfig::VALITOR_OPEN));
        $config->setCallbackNotification($this->urlInterface->getDirectUrl(ConstantConfig::VALITOR_NOTIFICATION));
        $config->setCallbackForm($this->urlInterface->getDirectUrl(ConstantConfig::VALITOR_CALLBACK));

        return $config;
    }

    public function getCheckoutSession()
    {
        return $this->checkoutSession;
    }

    /**
     * @param $couponCodeAmount
     * @param $order
     * @param $discountAllItems
     *
     * @return array
     */
    private function itemOrderLines($couponCodeAmount, $order, $discountAllItems)
    {
        $orderLines       = [];
        $couponCode       = $order->getDiscountDescription();
        $storePriceIncTax = $this->storeConfig->storePriceIncTax();

        foreach ($order->getAllItems() as $item) {
            $productType          = $item->getProductType();
            $productOriginalPrice = $item->getBaseOriginalPrice();
            $taxPercent           = $item->getTaxPercent();
            $appliedRule          = $this->discountHandler->getAppliedDiscounts($item);
            $discountAmount       = $item->getBaseDiscountAmount();
            $parentItemType       = "";

            if ($item->getParentItem()) {
                $parentItemType = $item->getParentItem()->getProductType();
            }
            if ($productType != "bundle" && $parentItemType != "configurable") {
                if ($productOriginalPrice == 0) {
                    $productOriginalPrice = $item->getPriceInclTax();
                }
                if ($storePriceIncTax) {
                    $unitPriceWithoutTax = $this->priceHandler->getPriceWithoutTax($productOriginalPrice, $taxPercent);
                    $unitPrice           = bcdiv($unitPriceWithoutTax, 1, 2);
                } else {
                    $unitPrice           = $productOriginalPrice;
                    $unitPriceWithoutTax = $productOriginalPrice;
                }
                $dataForPrice         = $this->priceHandler->dataForPrice(
                    $item,
                    $unitPrice,
                    $couponCode,
                    $this->discountHandler->getItemDiscount($discountAmount, $productOriginalPrice, $item->getQtyOrdered())
                );
                $taxAmount            = $dataForPrice["taxAmount"];
                $discount             = $this->discountHandler->orderLineDiscount(
                    $discountAllItems,
                    $dataForPrice["discount"]
                );
                $catalogDiscount      = $dataForPrice["catalogDiscount"];
                $itemTaxAmount        = $taxAmount + $item->getWeeeTaxAppliedRowAmount();
                $orderLines[]         = $this->orderLines->itemOrderLine(
                    $item,
                    $unitPrice,
                    $discount,
                    $itemTaxAmount,
                    $order,
                    true
                );
                $roundingCompensation = $this->priceHandler->compensationAmountCal(
                    $item,
                    $unitPrice,
                    $unitPriceWithoutTax,
                    $taxAmount,
                    $discount,
                    $couponCodeAmount,
                    $catalogDiscount,
                    $storePriceIncTax,
                    true
                );
                // check if rounding compensation amount, send in the separate orderline
                if ($roundingCompensation > 0 || $roundingCompensation < 0) {
                    $orderLines[] = $this->orderLines->compensationOrderLine(
                        "Compensation Amount",
                        "comp-" . $item->getItemId(),
                        $roundingCompensation
                    );
                }
            }
        }

        return $orderLines;
    }

    /**
     * @param $order
     *
     * @return mixed
     */
    private function restoreOrderAndReturnError($order)
    {
        $this->restoreOrderFromOrderId($order->getIncrementId());
        $requestParams['result']  = __(ConstantConfig::ERROR);
        $requestParams['message'] = __(ConstantConfig::ERROR_MESSAGE);

        return $requestParams;
    }

    /**
     * Prepare request to the valitor, sets the necessary parameters.
     *
     * @param $order
     * @param $orderLines
     * @param $orderId
     * @param $terminalId
     *
     * @return mixed
     */
    private function preparePaymentRequest($order, $orderLines, $orderId, $terminalId)
    {
        $storeScope = $this->storeConfig->getStoreScope();
        $storeCode  = $order->getStore()->getCode();
        //Test the conn with the Payment Gateway
        $auth     = $this->systemConfig->getAuth($storeCode);
        $api      = new TestAuthentication($auth);
        $response = $api->call();
        if (!$response) {
            return false;
        }
        $terminalName = $this->systemConfig->getTerminalConfig($terminalId, 'terminalname', $storeScope, $storeCode);
        //Transaction Info
        $transactionDetail = $this->helper->transactionDetail($orderId);
        $request           = new PaymentRequest($auth);
        $request->setTerminal($terminalName)
                ->setShopOrderId($order->getIncrementId())
                ->setAmount((float)number_format($order->getGrandTotal(), 2, '.', ''))
                ->setCurrency($order->getOrderCurrencyCode())
                ->setCustomerInfo($this->customerHandler->setCustomer($order))
                ->setConfig($this->setConfig())
                ->setTransactionInfo($transactionDetail)
                ->setSalesTax((float)number_format($order->getTaxAmount(), 2, '.', ''))
                ->setCookie($_SERVER['HTTP_COOKIE']);

        $post = $this->request->getPostValue();

        if (isset($post['tokenid'])) {
            $model      = $this->dataToken->create();
            $collection = $model->getCollection()->addFieldToFilter('id', $post['tokenid'])->getFirstItem();
            $data       = $collection->getData();
            if (!empty($data)) {
                $token = $data['token'];
                $request->setCcToken($token);
            }
        }

        if ($fraud = $this->systemConfig->getTerminalConfig($terminalId, 'fraud', $storeScope, $storeCode)) {
            $request->setFraudService($fraud);
        }

        if ($lang = $this->systemConfig->getTerminalConfig($terminalId, 'language', $storeScope, $storeCode)) {
            $langArr = explode('_', $lang, 2);
            if (isset($langArr[0])) {
                $request->setLanguage($langArr[0]);
            }
        }
        // check if auto capture enabled
        if ($this->systemConfig->getTerminalConfig($terminalId, 'capture', $storeScope, $storeCode)) {
            $request->setType('paymentAndCapture');
        }
        //set orderlines to the request
        $request->setOrderLines($orderLines);

        return $request;
    }

    /**
     * Send payment request to the valitor.
     *
     * @param $order
     * @param $request
     *
     * @return mixed
     */
    private function sendPaymentRequest($order, $request)
    {
        $storeScope = $this->storeConfig->getStoreScope();
        $storeCode  = $order->getStore()->getCode();

        try {
            /** @var \Valitor\Response\PaymentRequestResponse $response */
            $response                 = $request->call();
            $requestParams['result']  = __(ConstantConfig::SUCCESS);
            $requestParams['formurl'] = $response->Url;
            // set before payment status
            if ($this->systemConfig->getStatusConfig('before', $storeScope, $storeCode)) {
                $this->paymentHandler->setCustomOrderStatus($order, Order::STATE_NEW, 'before');
            }
            // set notification
            $order->addStatusHistoryComment(__(ConstantConfig::REDIRECT_TO_VALITOR) . $response->PaymentRequestId);
            $extensionAttribute = $order->getExtensionAttributes();
            if ($extensionAttribute && $extensionAttribute->getValitorPaymentFormUrl()) {
                $extensionAttribute->setValitorPaymentFormUrl($response->Url);
            }
            $order->setValitorPaymentFormUrl($response->Url);
            $order->setValitorPriceIncludesTax($this->storeConfig->storePriceIncTax());
            $order->getResource()->save($order);
            //set flag if customer redirect to Valitor
            $this->checkoutSession->setValitorCustomerRedirect(true);

            return $requestParams;
        } catch (ClientException $e) {
            $requestParams['result']  = __(ConstantConfig::ERROR);
            $requestParams['message'] = $e->getResponse()->getBody();
        } catch (ResponseHeaderException $e) {
            $requestParams['result']  = __(ConstantConfig::ERROR);
            $requestParams['message'] = $e->getHeader()->ErrorMessage;
        } catch (ResponseMessageException $e) {
            $requestParams['result']  = __(ConstantConfig::ERROR);
            $requestParams['message'] = $e->getMessage();
        } catch (\Exception $e) {
            $requestParams['result']  = __(ConstantConfig::ERROR);
            $requestParams['message'] = $e->getMessage();
        }

        $this->restoreOrderFromOrderId($order->getIncrementId());

        return $requestParams;
    }

    /**
     * @param $response
     * @param $storeCode
     * @param $storeScope
     *
     * @return bool|\Magento\Payment\Model\MethodInterface
     */
    private function isCaptured($response, $storeCode, $storeScope)
    {
        $isCaptured = false;
        foreach (SystemConfig::getTerminalCodes() as $terminalName) {
            $terminalConfig = $this->systemConfig->getTerminalConfigFromTerminalName(
                $terminalName,
                'terminalname',
                $storeScope,
                $storeCode
            );
            if ($terminalConfig === $response->Transactions[0]->Terminal) {
                $isCaptured = $this->systemConfig->getTerminalConfigFromTerminalName(
                    $terminalName,
                    'capture',
                    $storeScope,
                    $storeCode
                );
                break;
            }
        }

        return $isCaptured;
    }

    /**
     * @param $comment
     * @param $order
     */
    private function sendOrderConfirmationEmail($comment, $order)
    {
        $currentStatus        = $order->getStatus();
        $orderHistories       = $order->getStatusHistories();
        $latestHistoryComment = array_pop($orderHistories);
        $prevStatus           = $latestHistoryComment->getStatus();

        $sendMail = true;
        if (strpos($comment, ConstantConfig::NOTIFICATION_CALLBACK) !== false && $currentStatus == $prevStatus) {
            $sendMail = false;
        }
        if (!$order->getEmailSent() && $sendMail == true) {
            $this->orderSender->send($order);
        }
    }

    /**
     * @param RequestInterface $request
     * @param                  $avsCode
     * @param                  $historyComment
     *
     * @return bool
     */
    public function avsCheck(RequestInterface $request, $avsCode, $historyComment)
    {
        $checkRejectionCase = false;
        $transInfo          = null;
        $callback           = new Callback($request->getPostValue());
        $response           = $callback->call();
        if ($response) {
            $order                 = $this->loadOrderFromCallback($response);
            $storeScope            = \Magento\Store\Model\ScopeInterface::SCOPE_STORE;
            $storeCode             = $order->getStore()->getCode();            
            $transInfo             = sprintf(
                "Transaction ID: %s - Payment ID: %s - Credit card token: %s",
                $response->transactionId,
                $response->paymentId,
                $response->creditCardToken
            );
            $isAvsEnabled          = $this->checkAvsConfig($response, $storeCode, $storeScope, 'avscontrol');
            $isAvsEnforced         = $this->checkAvsConfig($response, $storeCode, $storeScope, 'enforceavs');
            $getAcceptedAvsResults = $this->getAcceptedAvsResults($response, $storeCode, $storeScope);

            if ($isAvsEnabled) {
                if ($isAvsEnforced && empty($avsCode)) {
                    $checkRejectionCase = true;
                } elseif (stripos($getAcceptedAvsResults, $avsCode) === false) {
                    $checkRejectionCase = true;
                }
            }
            if ($checkRejectionCase) {
                //check if order status set in configuration
                $statusKey         = Order::STATE_CANCELED;
                $orderStatusCancel = $this->systemConfig->getStatusConfig('cancel', $storeScope, $storeCode);
                //Save payment info in order to retrieve it for release operation
                if ($order->getId()) {
                    $this->savePaymentData($response, $order);
                }
                if ($orderStatusCancel) {
                    $statusKey = $orderStatusCancel;
                }
                $this->handleOrderStateAction($request, Order::STATE_CANCELED, $statusKey, $historyComment, $transInfo);
            }
        }

        return $checkRejectionCase;
    }

    /**
     * @param $response
     * @param $storeCode
     * @param $storeScope
     * @param $configField
     *
     * @return bool
     */
    public function checkAvsConfig($response, $storeCode, $storeScope, $configField)
    {
        $isEnabled = false;
        foreach (SystemConfig::getTerminalCodes() as $terminalName) {
            $terminalConfig = $this->systemConfig->getTerminalConfigFromTerminalName(
                $terminalName,
                'terminalname',
                $storeScope,
                $storeCode
            );
            if ($terminalConfig === $response->Transactions[0]->Terminal) {
                $isEnabled = $this->systemConfig->getTerminalConfigFromTerminalName(
                    $terminalName,
                    $configField,
                    $storeScope,
                    $storeCode
                );
                break;
            }
        }

        return $isEnabled;
    }

    /**
     * @param $response
     * @param $storeCode
     * @param $storeScope
     *
     * @return |null
     */
    public function getAcceptedAvsResults($response, $storeCode, $storeScope)
    {
        $acceptedAvsResults = null;
        foreach (SystemConfig::getTerminalCodes() as $terminalName) {
            $terminalConfig = $this->systemConfig->getTerminalConfigFromTerminalName(
                $terminalName,
                'terminalname',
                $storeScope,
                $storeCode
            );
            if ($terminalConfig === $response->Transactions[0]->Terminal) {
                $acceptedAvsResults = $this->systemConfig->getTerminalConfigFromTerminalName(
                    $terminalName,
                    'avs_acceptance',
                    $storeScope,
                    $storeCode
                );
                break;
            }
        }

        return $acceptedAvsResults;
    }

    /**
     * @param $response
     * @param $order
     */
    public function savePaymentData($response, $order)
    {
        $payment = $order->getPayment();
        $payment->setPaymentId($response->paymentId);
        $payment->setLastTransId($response->transactionId);
        $payment->save();
    }
}
