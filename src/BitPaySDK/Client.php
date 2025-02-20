<?php


namespace BitPaySDK;


use BitPayKeyUtils\KeyHelper\PrivateKey;
use BitPayKeyUtils\Storage\EncryptedFilesystemStorage;
use BitPayKeyUtils\Util\Util;
use BitPaySDK\Exceptions\BillCreationException;
use BitPaySDK\Exceptions\BillDeliveryException;
use BitPaySDK\Exceptions\BillQueryException;
use BitPaySDK\Exceptions\BillUpdateException;
use BitPaySDK\Exceptions\BitPayException;
use BitPaySDK\Exceptions\CurrencyQueryException;
use BitPaySDK\Exceptions\InvoiceCreationException;
use BitPaySDK\Exceptions\InvoiceUpdateException;
use BitPaySDK\Exceptions\InvoiceQueryException;
use BitPaySDK\Exceptions\InvoiceCancellationException;
use BitPaySDK\Exceptions\InvoicePaymentException;
use BitPaySDK\Exceptions\LedgerQueryException;
use BitPaySDK\Exceptions\PayoutRecipientCreationException;
use BitPaySDK\Exceptions\PayoutRecipientCancellationException;
use BitPaySDK\Exceptions\PayoutRecipientQueryException;
use BitPaySDK\Exceptions\PayoutRecipientUpdateException;
use BitPaySDK\Exceptions\PayoutRecipientNotificationException;
use BitPaySDK\Exceptions\PayoutCancellationException;
use BitPaySDK\Exceptions\PayoutCreationException;
use BitPaySDK\Exceptions\PayoutQueryException;
use BitPaySDK\Exceptions\PayoutUpdateException;
use BitPaySDK\Exceptions\PayoutNotificationException;
use BitPaySDK\Exceptions\PayoutBatchCreationException;
use BitPaySDK\Exceptions\PayoutBatchQueryException;
use BitPaySDK\Exceptions\PayoutBatchCancellationException;
use BitPaySDK\Exceptions\PayoutBatchNotificationException;
use BitPaySDK\Exceptions\RateQueryException;
use BitPaySDK\Exceptions\RefundCreationException;
use BitPaySDK\Exceptions\RefundUpdateException;
use BitPaySDK\Exceptions\RefundCancellationException;
use BitPaySDK\Exceptions\RefundNotificationException;
use BitPaySDK\Exceptions\RefundQueryException;
use BitPaySDK\Exceptions\SettlementQueryException;
use BitPaySDK\Exceptions\SubscriptionCreationException;
use BitPaySDK\Exceptions\SubscriptionQueryException;
use BitPaySDK\Exceptions\SubscriptionUpdateException;
use BitPaySDK\Exceptions\WalletQueryException;
use BitPaySDK\Model\Bill\Bill;
use BitPaySDK\Model\Facade;
use BitPaySDK\Model\Invoice\Invoice;
use BitPaySDK\Model\Invoice\Refund;
use BitPaySDK\Model\Wallet\Wallet;
use BitPaySDK\Model\Ledger\Ledger;
use BitPaySDK\Model\Payout\Payout;
use BitPaySDK\Model\Payout\PayoutBatch;
use BitPaySDK\Model\Payout\PayoutRecipient;
use BitPaySDK\Model\Payout\PayoutRecipients;
use BitPaySDK\Model\Rate\Rate;
use BitPaySDK\Model\Rate\Rates;
use BitPaySDK\Model\Settlement\Settlement;
use BitPaySDK\Model\Subscription\Subscription;
use BitPaySDK\Util\JsonMapper\JsonMapper;
use BitPaySDK\Util\RESTcli\RESTcli;
use Exception;
use Symfony\Component\Yaml\Yaml;

/**
 * Class Client
 * @package Bitpay
 * @author  Antonio Buedo
 * @version 6.1.2204 
 * See bitpay.com/api for more information.
 * date 15.11.2021
 */
class Client
{
    /**
     * @var Config
     */
    protected $_configuration;

    /**
     * @var string
     */
    protected $_env;

    /**
     * @var Tokens
     */
    protected $_tokenCache; // {facade, token}

    /**
     * @var string
     */
    protected $_configFilePath;

    /**
     * @var PrivateKey
     */
    protected $_ecKey;

    /**
     * @var RESTcli
     */
    protected $_RESTcli = null;

    /**
     * @var RESTcli
     */
    protected $_currenciesInfo = null;

    /**
     * Client constructor.
     */
    public function __construct()
    {
    }

    /**
     * Static constructor / factory
     */
    public static function create()
    {
        $instance = new self();

        return $instance;
    }

    /**
     * Constructor for use if the keys and SIN are managed by this library.
     *
     * @param $environment      String Target environment. Options: Env.Test / Env.Prod
     * @param $privateKey       String Private Key file path or the HEX value.
     * @param $tokens           Tokens containing the available tokens.
     * @param $privateKeySecret String|null Private Key encryption password.
     * @param $proxy            String|null The url of your proxy to forward requests through. Example:
     *                          http://********.com:3128
     * @return Client
     * @throws BitPayException BitPayException class
     */
    public function withData($environment, $privateKey, Tokens $tokens, $privateKeySecret = null, ?string $proxy = null)
    {
        try {
            $this->_env = $environment;
            $this->buildConfig($privateKey, $tokens, $privateKeySecret, $proxy);
            $this->initKeys();
            $this->init();

            return $this;
        } catch (Exception $e) {
            throw new BitPayException("failed to initialize BitPay Client (Config) : ".$e->getMessage());
        }
    }

    /**
     * Constructor for use if the keys and SIN are managed by this library.
     *
     * @param $configFilePath  String The path to the configuration file.
     * @return Client
     * @throws BitPayException BitPayException class
     */
    public function withFile($configFilePath)
    {
        try {
            $this->_configFilePath = $configFilePath;
            $this->getConfig();
            $this->initKeys();
            $this->init();

            return $this;
        } catch (Exception $e) {
            throw new BitPayException("failed to initialize BitPay Client (Config) : ".$e->getMessage());
        }
    }

    /**
     * Create a BitPay invoice.
     *
     * @param $invoice     Invoice An Invoice object with request parameters defined.
     * @param $facade      string The facade used to create it.
     * @param $signRequest bool Signed request.
     * @return $invoice Invoice A BitPay generated Invoice object.
     * @throws BitPayException BitPayException class
     */
    public function createInvoice(
        Invoice $invoice,
        string $facade = Facade::Merchant,
        bool $signRequest = true
    ): Invoice {
        try {
            $invoice->setToken($this->_tokenCache->getTokenByFacade($facade));
            $invoice->setGuid(Util::guid());

            $responseJson = $this->_RESTcli->post("invoices", $invoice->toArray(), $signRequest);
        } catch (BitPayException $e) {
            throw new InvoiceCreationException("failed to serialize Invoice object : ".$e->getMessage(), null, null, $e->getApiCode());
        } catch (Exception $e) {
            throw new InvoiceCreationException("failed to serialize Invoice object : ".$e->getMessage());
        }

        try {
            $mapper = new JsonMapper();
            $invoice = $mapper->map(
                json_decode($responseJson),
                new Invoice()
            );

        } catch (Exception $e) {
            throw new InvoiceCreationException(
                "failed to deserialize BitPay server response (Invoice) : ".$e->getMessage());
        }

        return $invoice;
    }

    /**
     * Update a BitPay invoice.
     *
     * @param $invoiceId    string The id of the invoice to updated.
     * @param $buyerEmail   string The buyer's email address.
     * @return $invoice     Invoice A BitPay updated Invoice object.
     * @throws InvoiceUpdateException InvoiceUpdateException class
     * @throws BitPayException BitPayException class
     */
    public function updateInvoice(
        string $invoiceId,
        string $buyerEmail
    ): Invoice {
        try {
            $params = [];
            $params["token"] = $this->_tokenCache->getTokenByFacade(Facade::Merchant);
            $params["buyerEmail"] = $buyerEmail;

            $responseJson = $this->_RESTcli->update("invoices/".$invoiceId, $params);
        } catch (BitPayException $e) {
            throw new InvoiceUpdateException("failed to serialize Invoice object : ".$e->getMessage(), null, null, $e->getApiCode());
        } catch (Exception $e) {
            throw new InvoiceUpdateException("failed to serialize Invoice object : ".$e->getMessage());
        }

        try {
            $mapper = new JsonMapper();
            $invoice = $mapper->map(
                json_decode($responseJson),
                new Invoice()
            );

        } catch (Exception $e) {
            throw new InvoiceUpdateException(
                "failed to deserialize BitPay server response (Invoice) : ".$e->getMessage());
        }

        return $invoice;
    }



    /**
     * Retrieve a BitPay invoice by invoice id using the specified facade.  The client must have been previously
     * authorized for the specified facade (the public facade requires no authorization).
     *
     * @param $invoiceId   string The id of the invoice to retrieve.
     * @param $facade      string The facade used to create it.
     * @param $signRequest bool Signed request.
     * @return Invoice A BitPay Invoice object.
     * @throws BitPayException BitPayException class
     */
    public function getInvoice(
        string $invoiceId,
        string $facade = Facade::Merchant,
        bool $signRequest = true
    ): Invoice {
        try {
            $params = [];
            $params["token"] = $this->_tokenCache->getTokenByFacade($facade);

            $responseJson = $this->_RESTcli->get("invoices/".$invoiceId, $params, $signRequest);
        } catch (BitPayException $e) {
            throw new InvoiceQueryException("failed to serialize Invoice object : ".$e->getMessage(), null, null, $e->getApiCode());
        } catch (Exception $e) {
            throw new InvoiceQueryException("failed to serialize Invoice object : ".$e->getMessage());
        }

        try {
            $mapper = new JsonMapper();
            $invoice = $mapper->map(
                json_decode($responseJson),
                new Invoice()
            );

        } catch (Exception $e) {
            throw new InvoiceQueryException(
                "failed to deserialize BitPay server response (Invoice) : ".$e->getMessage());
        }

        return $invoice;
    }

    /**
     * Retrieve a collection of BitPay invoices.
     *
     * @param $dateStart string The start of the date window to query for invoices. Format YYYY-MM-DD.
     * @param $dateEnd   string The end of the date window to query for invoices. Format YYYY-MM-DD.
     * @param $status    string|null The invoice status you want to query on.
     * @param $orderId   string|null The optional order id specified at time of invoice creation.
     * @param $limit     int|null Maximum results that the query will return (useful for paging results).
     * @param $offset    int|null Number of results to offset (ex. skip 10 will give you results starting with the 11th
     *                   result).
     * @return array     A list of BitPay Invoice objects.
     * @throws BitPayException BitPayException class
     */
    public function getInvoices(
        string $dateStart,
        string $dateEnd,
        string $status = null,
        string $orderId = null,
        int $limit = null,
        int $offset = null
    ): array {
        try {
            $params = [];
            $params["token"] = $this->_tokenCache->getTokenByFacade(Facade::Merchant);
            $params["dateStart"] = $dateStart;
            $params["dateEnd"] = $dateEnd;
            if ($status) {
                $params["status"] = $status;
            }
            if ($orderId) {
                $params["orderId"] = $orderId;
            }
            if ($limit) {
                $params["limit"] = $limit;
            }
            if ($offset) {
                $params["offset"] = $offset;
            }

            $responseJson = $this->_RESTcli->get("invoices", $params);
        } catch (BitPayException $e) {
            throw new InvoiceQueryException("failed to serialize Invoice object : ".$e->getMessage(), null, null, $e->getApiCode());
        } catch (Exception $e) {
            throw new InvoiceQueryException("failed to serialize Invoice object : ".$e->getMessage());
        }

        try {
            $mapper = new JsonMapper();
            $invoices = $mapper->mapArray(
                json_decode($responseJson),
                [],
                'BitPaySDK\Model\Invoice\Invoice'
            );

        } catch (Exception $e) {
            throw new InvoiceQueryException(
                "failed to deserialize BitPay server response (Invoice) : ".$e->getMessage());
        }

        return $invoices;
    }

    /**
     * Request a BitPay Invoice Webhook.
     *
     * @param $invoiceId     string A BitPay invoice ID.
     * @return $result      bool True if the webhook was successfully requested, false otherwise.
     * @throws InvoiceNotificationException InvoiceNotificationException class
     * @throws BitPayException BitPayException class
     */
    public function requestInvoiceNotification(string $invoiceId): bool
    {
        try {
            $params = [];
            $invoice = $this->getInvoice($invoiceId);
                    
        } catch (BitPayException $e) {
            throw new InvoiceNotificationException("failed to serialize invoice object : ".$e->getMessage(), null, null, $e->getApiCode());
        } catch (Exception $e) {
            throw new InvoiceNotificationException("failed to serialize invoice object : ".$e->getMessage());
        }

        $params["token"] = $invoice->getToken();
        
        try {
            $responseJson = $this->_RESTcli->post("invoices/".$invoiceId."/notifications", $params);
            $result = strtolower(json_decode($responseJson)) == "success";
        } catch (Exception $e) {
            throw new InvoiceNotificationException(
                "failed to deserialize BitPay server response (Invoice) : ".$e->getMessage());
        }

        return $result;
    }

    /**
     * Cancel a BitPay invoice.
     *
     * @param $invoiceId    string The id of the invoice to updated.
     * @return $invoice     Invoice Cancelled invoice object.
     * @throws InvoiceCancellationException InvoiceCancellationException class
     * @throws BitPayException BitPayException class
     */
    public function cancelInvoice(
        string $invoiceId, bool $forceCancel = false
    ): Invoice {
        try {
            $params = [];
            $params["token"] = $this->_tokenCache->getTokenByFacade(Facade::Merchant);
            if ($forceCancel) {
                $params["forceCancel"] = $forceCancel;
            } 

            $responseJson = $this->_RESTcli->delete("invoices/".$invoiceId, $params);
        } catch (BitPayException $e) {
            throw new InvoiceCancellationException("failed to serialize Invoice object : ".$e->getMessage(), null, null, $e->getApiCode());
        } catch (Exception $e) {
            throw new InvoiceCancellationException("failed to serialize Invoice object : ".$e->getMessage());
        }

        try {
            $mapper = new JsonMapper();
            $invoice = $mapper->map(
                json_decode($responseJson),
                new Invoice()
            );

        } catch (Exception $e) {
            throw new InvoiceCancellationException(
                "failed to deserialize BitPay server response (Invoice) : ".$e->getMessage());
        }

        return $invoice;
    }

    /**
     * Pay an invoice with a mock transaction
     *
     * @param $invoiceId    string The id of the invoice.
     * @param $complete     bool indicate if paid invoice should have status if complete true or a confirmed status
     * @return $invoice     Invoice object.
     * @throws InvoicePaymentException InvoicePaymentException class
     * @throws BitPayException BitPayException class
     */
    public function payInvoice(
        string $invoiceId,
        string $status,
        bool $complete = true
    ): Invoice {
        if (strtolower($this->_env) != "test")
        {
            throw new InvoicePaymentException("Pay Invoice method only available in test or demo environments");
        }

        try {
            $params = [];
            $params["token"] = $this->_tokenCache->getTokenByFacade(Facade::Merchant);
            $params["status"] = $status;
            $params["complete"] = $complete;
            $responseJson = $this->_RESTcli->update("invoices/pay/".$invoiceId, $params, true);  
        } catch (BitPayException $e) {
            throw new InvoicePaymentException("failed to serialize Invoice object : ".$e->getMessage(), null, null, $e->getApiCode());
        } catch (Exception $e) {
            throw new InvoicePaymentException("failed to serialize Invoice object : ".$e->getMessage());
        }

        try {
            $mapper = new JsonMapper();
            $invoice = $mapper->map(
                json_decode($responseJson),
                new Invoice()
            );
        } catch (Exception $e) {
            throw new InvoicePaymentException(
                "failed to deserialize BitPay server response (Invoice) : ".$e->getMessage());
        }

        return $invoice;
    }


    /**
     * Create a refund for a BitPay invoice.
     *
     * @param $invoiceId          string The BitPay invoice Id having the associated refund to be created.
     * @param $amount             float Amount to be refunded in the currency indicated.
     * @param $currency           string Reference currency used for the refund, usually the same as the currency used to create the invoice.
     * @param $preview            bool Whether to create the refund request as a preview (which will not be acted on until status is updated)
     * @param $immediate          bool Whether funds should be removed from merchant ledger immediately on submission or at time of processing
     * @param $buyerPaysRefundFee bool Whether the buyer should pay the refund fee (default is merchant)
     * @return $refund            Refund An updated Refund Object
     * @throws RefundCreationException RefundCreationException class
     * @throws BitPayException BitPayException class
     */
    public function createRefund(
        string $invoiceId,
        float $amount,
        string $currency,
        bool $preview = false,
        bool $immediate = false,
        bool $buyerPaysRefundFee = false
    ): Refund {
        $params = [];
        $params["token"] = $this->_tokenCache->getTokenByFacade(Facade::Merchant);
        $params["invoiceId"] =  $invoiceId;
        $params["amount"] = $amount;
        $params["currency"] = $currency;
        $params["preview"] = $preview;
        $params["immediate"] = $immediate;
        $params["buyerPaysRefundFee"] = $buyerPaysRefundFee;
        
        try {
            $responseJson = $this->_RESTcli->post("refunds/", $params, true);
        } catch (BitPayException $e) {
            throw new RefundCreationException("failed to serialize refund object : ".$e->getMessage(), null, null, $e->getApiCode());
        } catch (Exception $e) {
            throw new RefundCreationException("failed to serialize refund object : ".$e->getMessage());
        }

        try {
            $mapper = new JsonMapper();
            $refund = $mapper->map(
                json_decode($responseJson),
                new Refund()
            );

        } catch (Exception $e) {
            throw new RefundCreationException(
                "failed to deserialize BitPay server response (Refund) : ".$e->getMessage());
        }

        return $refund;
    }

    /**
     * Update the status of a BitPay invoice.
     *
     * @param $refundId     string BitPay refund ID.
     * @param $status       string The new status for the refund to be updated.
     * @return $refund      Refund A BitPay generated Refund object.
     * @throws RefundUpdateException RefundUpdateException class
     * @throws BitPayException BitPayException class
     */
    public function updateRefund(
        string $refundId,
        string $status
    ): Refund {
        $params = [];
        $params["token"] = $this->_tokenCache->getTokenByFacade(Facade::Merchant);
        $params["status"] = $status;

        try {
            $responseJson = $this->_RESTcli->update("refunds/".$refundId, $params);
        } catch (BitPayException $e) {
            throw new RefundUpdateException("failed to serialize refund object : ".$e->getMessage(), null, null, $e->getApiCode());
        } catch (Exception $e) {
            throw new RefundUpdateException("failed to serialize refund object : ".$e->getMessage());
        }

        try {
            $mapper = new JsonMapper();
            $refund = $mapper->map(
                json_decode($responseJson),
                new Refund()
            );
 
        } catch (Exception $e) {
            throw new RefundUpdateException(
                "failed to deserialize BitPay server response (Refund) : ".$e->getMessage());
        }

        return $refund;
    }

    /**
     * Retrieve all refund requests on a BitPay invoice.
     *
     * @param $invoiceId    string The BitPay invoice object having the associated refunds.
     * @return $refunds     array list of BitPay Refund objects with the associated Refund objects.
     * @throws RefundQueryException RefundQueryException class
     * @throws BitPayException BitPayException class
     */
    public function getRefunds(
        string $invoiceId
    ): array {
        $params = [];
        $params["token"] = $this->_tokenCache->getTokenByFacade(Facade::Merchant);
        $params["invoiceId"] = $invoiceId;

        try {
            $responseJson = $this->_RESTcli->get("refunds/", $params, true);
        } catch (BitPayException $e) {
            throw new RefundQueryException("failed to serialize refund object : ".$e->getMessage(), null, null, $e->getApiCode());
        } catch (Exception $e) {
            throw new RefundQueryException("failed to serialize refund object : ".$e->getMessage());
        }

        try {
            $mapper = new JsonMapper();
            $refunds = $mapper->mapArray(
                json_decode($responseJson),
                [],
                'BitPaySDK\Model\Invoice\Refund'
            );

        } catch (Exception $e) {
            throw new RefundQueryException(
                "failed to deserialize BitPay server response (Refund) : ".$e->getMessage());
        }

        return $refunds;
    }

    /**
     * Retrieve a previously made refund request on a BitPay invoice.
     *
     * @param $refundId     string The BitPay refund ID.
     * @return $refund      Refund BitPay Refund object with the associated Refund object.
     * @throws RefundQueryException RefundQueryException class
     * @throws BitPayException BitPayException class
     */
    public function getRefund(
        string $refundId
    ): Refund {
        $params = [];
        $params["token"] = $this->_tokenCache->getTokenByFacade(Facade::Merchant);

        try {
            $responseJson = $this->_RESTcli->get("refunds/".$refundId, $params, true);
        } catch (BitPayException $e) {
            throw new RefundQueryException("failed to serialize refund object : ".$e->getMessage(), null, null, $e->getApiCode());
        } catch (Exception $e) {
            throw new RefundQueryException("failed to serialize refund object : ".$e->getMessage());
        }

        try {
            $mapper = new JsonMapper();
            $refund = $mapper->map(
                json_decode($responseJson),
                new Refund()
            );

        } catch (Exception $e) {
            throw new RefundQueryException(
                "failed to deserialize BitPay server response (Refund) : ".$e->getMessage());
        }

        return $refund;
    }

    /**
     * Send a refund notification.
     *
     * @param $refundId     string A BitPay refund ID.
     * @return $result      bool An updated Refund Object
     * @throws RefundCreationException RefundCreationException class
     * @throws BitPayException BitPayException class
     */
    public function sendRefundNotification(string $refundId): bool
    {
        $params = [];
        $params["token"] = $this->_tokenCache->getTokenByFacade(Facade::Merchant);
        
        try {
            $responseJson = $this->_RESTcli->post("refunds/".$refundId."/notifications", $params, true);        
        } catch (BitPayException $e) {
            throw new RefundNotificationException("failed to serialize refund object : ".$e->getMessage(), null, null, $e->getApiCode());
        } catch (Exception $e) {
            throw new RefundNotificationException("failed to serialize refund object : ".$e->getMessage());
        }
        
        try {
            $result = json_decode($responseJson)->status == "success";
        } catch (Exception $e) {
            throw new RefundNotificationException(
                "failed to deserialize BitPay server response (Refund) : ".$e->getMessage());
        }

        return $result;
    }


    /**
     * Cancel a previously submitted refund request on a BitPay invoice.
     *
     * @param $refundId     string The refund Id for the refund to be canceled.
     * @return $refund      Refund Cancelled refund Object.
     * @throws RefundCancellationException RefundCancellationException class
     * @throws BitPayException BitPayException class
     */
    public function cancelRefund(string $refundId): Refund
    {
        $params = [];
        $params["token"] = $this->_tokenCache->getTokenByFacade(Facade::Merchant);

        try {
            $responseJson = $this->_RESTcli->delete("refunds/".$refundId, $params);
        } catch (BitPayException $e) {
            throw new RefundCancellationException("failed to serialize refund object : ".$e->getMessage(), null, null, $e->getApiCode());
        } catch (Exception $e) {
            throw new RefundCancellationException("failed to serialize refund object : ".$e->getMessage());
        }

        try {
            $mapper = new JsonMapper();
            $refund = $mapper->map(
                json_decode($responseJson),
                new Refund()
            );

        } catch (Exception $e) {
            throw new RefundCancellationException(
                "failed to deserialize BitPay server response (Refund) : ".$e->getMessage());
        }

        return $refund;
    }

    /**
     * Retrieve all supported wallets.
     *
     * @return $wallets     array A list of wallet objets.
     * @throws WalletQueryException WalletQueryException class
     * @throws BitPayException BitPayException class
     */
    public function getSupportedWallets(): array
    {
        try {
            $responseJson = $this->_RESTcli->get("supportedWallets/", null, false);
        } catch (BitPayException $e) {
            throw new WalletQueryException("failed to serialize Wallet object : ".$e->getMessage(), null, null, $e->getApiCode());
        } catch (Exception $e) {
            throw new WalletQueryException("failed to deserialize BitPay server response (Wallet) : " + $e->getMessage());
        }

        try {
            $mapper = new JsonMapper();
            $wallets = $mapper->mapArray(
                json_decode($responseJson),
                [],
                'BitPaySDK\Model\Wallet\Wallet'
            );

        } catch (Exception $e) {
            throw new WalletQueryException("failed to deserialize BitPay server response (Wallet) : " + $e->getMessage());
        }

        return $wallets;
    }

    /**
     * Create a BitPay Bill.
     *
     * @param $bill        Bill A Bill object with request parameters defined.
     * @param $facade      string The facade used to create it.
     * @param $signRequest bool Signed request.
     * @return Bill A BitPay generated Bill object.
     * @throws BitPayException BitPayException class
     */
    public function createBill(Bill $bill, string $facade = Facade::Merchant, bool $signRequest = true): Bill
    {
        try {
            $bill->setToken($this->_tokenCache->getTokenByFacade($facade));

            $responseJson = $this->_RESTcli->post("bills", $bill->toArray(), $signRequest);
        } catch (BitPayException $e) {
            throw new BillCreationException("failed to serialize Bill object : ".$e->getMessage(), null, null, $e->getApiCode());
        } catch (Exception $e) {
            throw new BillCreationException("failed to serialize Bill object : ".$e->getMessage());
        }

        try {
            $mapper = new JsonMapper();
            $bill = $mapper->map(
                json_decode($responseJson),
                new Bill()
            );

        } catch (Exception $e) {
            throw new BillCreationException(
                "failed to deserialize BitPay server response (Bill) : ".$e->getMessage());
        }

        return $bill;
    }

    /**
     * Retrieve a BitPay bill by bill id using the specified facade.
     *
     * @param $billId      string The id of the bill to retrieve.
     * @param $facade      string The facade used to retrieve it.
     * @param $signRequest bool Signed request.
     * @return Bill A BitPay Bill object.
     * @throws BitPayException BitPayException class
     */
    public function getBill(string $billId, string $facade = Facade::Merchant, bool $signRequest = true): Bill
    {

        try {
            $params = [];
            $params["token"] = $this->_tokenCache->getTokenByFacade($facade);

            $responseJson = $this->_RESTcli->get("bills/".$billId, $params, $signRequest);
        } catch (BitPayException $e) {
            throw new BillQueryException("failed to serialize Bill object : ".$e->getMessage(), null, null, $e->getApiCode());
        } catch (Exception $e) {
            throw new BillQueryException("failed to serialize Bill object : ".$e->getMessage());
        }

        try {
            $mapper = new JsonMapper();
            $bill = $mapper->map(
                json_decode($responseJson),
                new Bill()
            );

        } catch (Exception $e) {
            throw new BillQueryException(
                "failed to deserialize BitPay server response (Bill) : ".$e->getMessage());
        }

        return $bill;
    }

    /**
     * Retrieve a collection of BitPay bills.
     *
     * @param $status string|null The status to filter the bills.
     * @return array A list of BitPay Bill objects.
     * @throws BitPayException BitPayException class
     */
    public function getBills(string $status = null): array
    {
        try {
            $params = [];
            $params["token"] = $this->_tokenCache->getTokenByFacade(Facade::Merchant);
            if ($status) {
                $params["status"] = $status;
            }

            $responseJson = $this->_RESTcli->get("bills", $params);
        } catch (BitPayException $e) {
            throw new BillQueryException("failed to serialize Bill object : ".$e->getMessage(), null, null, $e->getApiCode());
        } catch (Exception $e) {
            throw new BillQueryException("failed to serialize Bill object : ".$e->getMessage());
        }

        try {
            $mapper = new JsonMapper();
            $bills = $mapper->mapArray(
                json_decode($responseJson),
                [],
                'BitPaySDK\Model\Bill\Bill'
            );

        } catch (Exception $e) {
            throw new BillQueryException(
                "failed to deserialize BitPay server response (Bill) : ".$e->getMessage());
        }

        return $bills;
    }

    /**
     * Update a BitPay Bill.
     *
     * @param $bill   Bill A Bill object with the parameters to update defined.
     * @param $billId string $billIdThe Id of the Bill to update.
     * @return Bill An updated Bill object.
     * @throws BitPayException BitPayException class
     */
    public function updateBill(Bill $bill, string $billId): Bill
    {
        try {
            $billToken = $this->getBill($bill->getId())->getToken();
            $bill->setToken($billToken);

            $responseJson = $this->_RESTcli->update("bills/".$billId, $bill->toArray());
        } catch (BitPayException $e) {
            throw new BillUpdateException("failed to serialize Bill object : ".$e->getMessage(), null, null, $e->getApiCode());
        } catch (Exception $e) {
            throw new BillUpdateException("failed to serialize Bill object : ".$e->getMessage());
        }

        try {
            $mapper = new JsonMapper();
            $bill = $mapper->map(
                json_decode($responseJson),
                $bill
            );

        } catch (Exception $e) {
            throw new BillUpdateException("failed to deserialize BitPay server response (Bill) : ".$e->getMessage());
        }

        return $bill;
    }

    /**
     * Deliver a BitPay Bill.
     *
     * @param $billId      string The id of the requested bill.
     * @param $billToken   string The token of the requested bill.
     * @param $signRequest bool Allow unsigned request
     * @return string A response status returned from the API.
     * @throws BitPayException BitPayException class
     */
    public function deliverBill(string $billId, string $billToken, bool $signRequest = true): string
    {
        try {
            $responseJson = $this->_RESTcli->post(
                "bills/".$billId."/deliveries", ['token' => $billToken], $signRequest);
        } catch (BitPayException $e) {
            throw new BillDeliveryException("failed to serialize Bill object : ".$e->getMessage(), null, null, $e->getApiCode());
        } catch (Exception $e) {
            throw new BillDeliveryException("failed to serialize Bill object : ".$e->getMessage());
        }

        try {
            $result = str_replace("\"", "", $responseJson);
        } catch (Exception $e) {
            throw new BillDeliveryException("failed to deserialize BitPay server response (Bill) : ".$e->getMessage());
        }

        return $result;
    }

    /**
     * Retrieve the exchange rate table maintained by BitPay.  See https://bitpay.com/bitcoin-exchange-rates.
     *
     * @return Rates A Rates object populated with the BitPay exchange rate table.
     * @throws BitPayException BitPayException class
     */
    public function getRates(): Rates
    {
        try {
            $responseJson = $this->_RESTcli->get("rates", null, false);
        } catch (BitPayException $e) {
            throw new RateQueryException("failed to serialize Rates object : ".$e->getMessage(), null, null, $e->getApiCode());
        } catch (Exception $e) {
            throw new RateQueryException("failed to serialize Rates object : ".$e->getMessage());
        }

        try {
            $mapper = new JsonMapper();
            $rates = $mapper->mapArray(
                json_decode($responseJson),
                [],
                'BitPaySDK\Model\Rate\Rate'
            );

        } catch (Exception $e) {
            throw new RateQueryException(
                "failed to deserialize BitPay server response (Rates) : ".$e->getMessage());
        }

        return new Rates($rates, $this);
    }

    /**
     * Retrieve all the rates for a given cryptocurrency
     *
     * @param string $baseCurrency The cryptocurrency for which you want to fetch the rates.
     *                             Current supported values are BTC, BCH, ETH, XRP, DOGE and LTC
     * @return Rates A Rates object populated with the currency rates for the requested baseCurrency.
     * @throws BitPayException BitPayException class
     */
    public function getCurrencyRates(string $baseCurrency): Rates
    {
        try {
            $responseJson = $this->_RESTcli->get("rates/".$baseCurrency, null, false);
        } catch (BitPayException $e) {
            throw new RateQueryException("failed to serialize Rates object : ".$e->getMessage(), null, null, $e->getApiCode());
        } catch (Exception $e) {
            throw new RateQueryException("failed to serialize Rates object : ".$e->getMessage());
        }

        try {
            $mapper = new JsonMapper();
            $rates = $mapper->mapArray(
                json_decode($responseJson),
                [],
                'BitPaySDK\Model\Rate\Rate'
            );

        } catch (Exception $e) {
            throw new RateQueryException(
                "failed to deserialize BitPay server response (Rates) : ".$e->getMessage());
        }

        return new Rates($rates, $this);
    }

    /**
     * Retrieve the rate for a cryptocurrency / fiat pair
     *
     * @param string $baseCurrency The cryptocurrency for which you want to fetch the fiat-equivalent rate.
     *                             Current supported values are BTC, BCH, ETH, XRP, DOGE and LTC
     * @param string $currency The fiat currency for which you want to fetch the baseCurrency rate
     * @return Rate A Rate object populated with the currency rate for the requested baseCurrency.
     * @throws BitPayException BitPayException class
     */
    public function getCurrencyPairRate(string $baseCurrency, string $currency): Rate
    {
        try {
            $responseJson = $this->_RESTcli->get("rates/".$baseCurrency."/".$currency, null, false);
        } catch (BitPayException $e) {
            throw new RateQueryException("failed to serialize Rates object : ".$e->getMessage(), null, null, $e->getApiCode());
        } catch (Exception $e) {
            throw new RateQueryException("failed to serialize Rate object : ".$e->getMessage());
        }

        try {
            $mapper = new JsonMapper();
            $rate = $mapper->map(
                json_decode($responseJson),
                new Rate()
            );

        } catch (Exception $e) {
            throw new RateQueryException(
                "failed to deserialize BitPay server response (Rate) : ".$e->getMessage());
        }

        return $rate;
    }

    /**
     * Retrieve a list of ledgers by date range using the merchant facade.
     *
     * @param $currency  string The three digit currency string for the ledger to retrieve.
     * @param $startDate string The first date for the query filter.
     * @param $endDate   string The last date for the query filter.
     * @return Ledger A Ledger object populated with the BitPay ledger entries list.
     * @throws BitPayException BitPayException class
     */
    public function getLedger(string $currency, string $startDate, string $endDate): array
    {
        try {
            $params = [];
            $params["token"] = $this->_tokenCache->getTokenByFacade(Facade::Merchant);
            if ($currency) {
                $params["currency"] = $currency;
            }
            if ($currency) {
                $params["startDate"] = $startDate;
            }
            if ($currency) {
                $params["endDate"] = $endDate;
            }

            $responseJson = $this->_RESTcli->get("ledgers/".$currency, $params);
        } catch (BitPayException $e) {
            throw new LedgerQueryException("failed to serialize Ledger object : ".$e->getMessage(), null, null, $e->getApiCode());
        } catch (Exception $e) {
            throw new LedgerQueryException("failed to serialize Ledger object : ".$e->getMessage());
        }

        try {
            $mapper = new JsonMapper();
            $ledger = $mapper->mapArray(
                json_decode($responseJson),
                [],
                'BitPaySDK\Model\Ledger\LedgerEntry'
            );

        } catch (Exception $e) {
            throw new LedgerQueryException(
                "failed to deserialize BitPay server response (Ledger) : ".$e->getMessage());
        }

        return $ledger;
    }

    /**
     * Retrieve a list of ledgers using the merchant facade.
     *
     * @return array A list of Ledger objects populated with the currency and current balance of each one.
     * @throws BitPayException BitPayException class
     */
    public function getLedgers(): array
    {
        try {
            $params = [];
            $params["token"] = $this->_tokenCache->getTokenByFacade(Facade::Merchant);

            $responseJson = $this->_RESTcli->get("ledgers", $params);
        } catch (BitPayException $e) {
            throw new LedgerQueryException("failed to serialize Ledger object : ".$e->getMessage(), null, null, $e->getApiCode());
        } catch (Exception $e) {
            throw new LedgerQueryException("failed to serialize Ledger object : ".$e->getMessage());
        }

        try {
            $mapper = new JsonMapper();
            $ledgers = $mapper->mapArray(
                json_decode($responseJson),
                [],
                'BitPaySDK\Model\Ledger\Ledger'
            );

        } catch (Exception $e) {
            throw new LedgerQueryException(
                "failed to deserialize BitPay server response (Ledger) : ".$e->getMessage());
        }

        return $ledgers;
    }

    /**
     * Submit BitPay Payout Recipients.
     *
     * @param $recipients PayoutRecipients A PayoutRecipients object with request parameters defined.
     * @return array A list of BitPay PayoutRecipients objects..
     * @throws PayoutRecipientCreationException BitPayException class
     */
    public function submitPayoutRecipients(PayoutRecipients $recipients): array
    {
        try {
            $recipients->setToken($this->_tokenCache->getTokenByFacade(Facade::Payout));
            $recipients->setGuid(Util::guid());

            $responseJson = $this->_RESTcli->post("recipients", $recipients->toArray());
        } catch (BitPayException $e) {
            throw new PayoutRecipientCreationException("failed to serialize PayoutRecipients object : ".$e->getMessage(), null, null, $e->getApiCode());
        } catch (Exception $e) {
            throw new PayoutRecipientCreationException("failed to serialize PayoutRecipients object : ".$e->getMessage());
        }
        try {
            $mapper = new JsonMapper();
            $recipients = $mapper->mapArray(
                json_decode($responseJson),
                [],
                'BitPaySDK\Model\Payout\PayoutRecipient'
            );

        } catch (Exception $e) {
            throw new PayoutRecipientCreationException(
                "failed to deserialize BitPay server response (PayoutRecipients) : ".$e->getMessage());
        }

        return $recipients;
    }

    /**
     * Retrieve a BitPay payout recipient by batch id using.  The client must have been previously authorized for the
     * payout facade.
     *
     * @param $recipientId string The id of the recipient to retrieve.
     * @return PayoutRecipient A BitPay PayoutRecipient object.
     * @throws PayoutQueryException BitPayException class
     */
    public function getPayoutRecipient(string $recipientId): PayoutRecipient
    {
        try {
            $params = [];
            $params["token"] = $this->_tokenCache->getTokenByFacade(Facade::Payout);

            $responseJson = $this->_RESTcli->get("recipients/".$recipientId, $params);
        } catch (BitPayException $e) {
            throw new PayoutRecipientQueryException("failed to serialize PayoutRecipients object : ".$e->getMessage(), null, null, $e->getApiCode());
        } catch (Exception $e) {
            throw new PayoutRecipientQueryException("failed to serialize PayoutRecipient object : ".$e->getMessage());
        }

        try {
            $mapper = new JsonMapper();
            $recipient = $mapper->map(
                json_decode($responseJson),
                new PayoutRecipient()
            );

        } catch (Exception $e) {
            throw new PayoutQueryException(
                "failed to deserialize BitPay server response (PayoutRecipient) : ".$e->getMessage());
        }

        return $recipient;
    }

    /**
     * Retrieve a collection of BitPay Payout Recipients.
     *
     * @param $status    string|null The recipient status you want to query on.
     * @param $limit     int|null Maximum results that the query will return (useful for paging results).
     * @param $offset     int|null Number of results to offset (ex. skip 10 will give you results starting with the 11th result).
     * @return array     A list of BitPayRecipient objects.
     * @throws BitPayException BitPayException class
     */
    public function getPayoutRecipients(string $status = null, int $limit = null, int $offset = null): array 
    {
        try {
            $params = [];
            $params["token"] = $this->_tokenCache->getTokenByFacade(Facade::Payout);

            if ($status) {
                $params["status"] = $status;
            }
            if ($limit) {
                $params["limit"] = $limit;
            }
            if ($offset) {
                $params["offset"] = $offset;
            }

            $responseJson = $this->_RESTcli->get("recipients", $params);
        } catch (BitPayException $e) {
            throw new PayoutRecipientQueryException("failed to serialize PayoutRecipients object : ".$e->getMessage(), null, null, $e->getApiCode());
        } catch (Exception $e) {
            throw new PayoutRecipientQueryException("failed to serialize PayoutRecipients object : ".$e->getMessage());
        }

        try {
            $mapper = new JsonMapper();
            $recipients = $mapper->mapArray(
                json_decode($responseJson),
                [],
                'BitPaySDK\Model\Payout\PayoutRecipient'
            );

        } catch (Exception $e) {
            throw new PayoutRecipientQueryException(
                "failed to deserialize BitPay server response (PayoutRecipients) : ".$e->getMessage());
        }

        return $recipients;
    }

    /**
     * Update a Payout Recipient.
     *
     * @param $recipientId string The recipient id for the recipient to be updated.
     * @param $recipient   PayoutRecipient A PayoutRecipient object with updated parameters defined.
     * @return PayoutRecipient Uhe updated recipient object.
     * @throws PayoutRecipientUpdateException PayoutRecipientUpdateException class
     */
    public function updatePayoutRecipient(string $recipientId, PayoutRecipient $recipient): PayoutRecipient
    {
        try {
            $recipient->setToken($this->_tokenCache->getTokenByFacade(Facade::Payout));

            $responseJson = $this->_RESTcli->update("recipients/".$recipientId, $recipient->toArray());
        } catch (BitPayException $e) {
            throw new PayoutRecipientUpdateException("failed to serialize PayoutRecipient object : ".$e->getMessage(), null, null, $e->getApiCode());
        } catch (Exception $e) {
            throw new PayoutRecipientUpdateException("failed to serialize PayoutRecipient object : ".$e->getMessage());
        }

        try {
            $mapper = new JsonMapper();
            $recipient = $mapper->map(
                json_decode($responseJson),
                new PayoutRecipient()
            );

        } catch (Exception $e) {
            throw new PayoutRecipientUpdateException(
                "failed to deserialize BitPay server response (PayoutRecipient) : ".$e->getMessage());
        }

        return $recipient;
    }

    /**
     * Delete a Payout Recipient.
     *
     * @param $recipientId string The recipient id for the recipient to be deleted.
     * @return bool True if the recipient was successfully deleted, false otherwise.
     * @throws PayoutRecipientCancellationException PayoutRecipientCancellationException class
     */
    public function deletePayoutRecipient(string $recipientId): bool
    {
        try {
            $params = [];
            $params["token"] = $this->_tokenCache->getTokenByFacade(Facade::Payout);

            $responseJson = $this->_RESTcli->delete("recipients/".$recipientId, $params);
        } catch (BitPayException $e) {
            throw new PayoutRecipientCancellationException("failed to serialize PayoutRecipient object : ".$e->getMessage(), null, null, $e->getApiCode());
        } catch (Exception $e) {
            throw new PayoutRecipientCancellationException("failed to serialize PayoutRecipient object : ".$e->getMessage());
        }

        try {
            $result = strtolower(json_decode($responseJson)->status) == "success";

        } catch (Exception $e) {
            throw new PayoutRecipientCancellationException(
                "failed to deserialize BitPay server response (PayoutRecipient) : ".$e->getMessage());
        }

        return $result;
    }

    /**
     * Notify BitPay Payout Recipient.
     *
     * @param  $recipientId string The id of the recipient to notify.
     * @return bool True if the notification was successfully sent, false otherwise.
     * @throws PayoutRecipientNotificationException PayoutRecipientNotificationException class
     */
    public function requestPayoutRecipientNotification(string $recipientId): bool
    {
        try {
            $content = [];
            $content["token"] = $this->_tokenCache->getTokenByFacade(Facade::Payout);

            $responseJson = $this->_RESTcli->post("recipients/".$recipientId."/notifications", $content);
        } catch (BitPayException $e) {
            throw new PayoutRecipientNotificationException("failed to serialize PayoutRecipient object : ".$e->getMessage(), null, null, $e->getApiCode());
        } catch (Exception $e) {
            throw new PayoutRecipientNotificationException("failed to serialize PayoutRecipient object : ".$e->getMessage());
        }

        try {
            $result = strtolower(json_decode($responseJson)->status) == "success";

        } catch (Exception $e) {
            throw new PayoutRecipientNotificationException(
                "failed to deserialize BitPay server response (PayoutRecipient) : ".$e->getMessage());
        }

        return $result;
    }

    /**
     * Submit a BitPay Payout.
     *
     * @param $payout Payout A Payout object with request parameters defined.
     * @return Payout A Payout BitPay generated Payout object.
     * @throws PayoutCreationException BitPayException class
     */
    public function submitPayout(Payout $payout): Payout
    {
        try {
            $payout->setToken($this->_tokenCache->getTokenByFacade(Facade::Payout));

            $precision = $this->getCurrencyInfo($payout->getCurrency())->precision ?? 2;
            $payout->formatAmount($precision);

            $responseJson = $this->_RESTcli->post("payouts", $payout->toArray());
        } catch (BitPayException $e) {
            throw new PayoutCreationException("failed to serialize Payout object : ".$e->getMessage(), null, null, $e->getApiCode());
        } catch (Exception $e) {
            throw new PayoutCreationException("failed to serialize Payout object : ".$e->getMessage());
        }

        try {
            $mapper = new JsonMapper();
            $payout = $mapper->map(
                json_decode($responseJson),
                new Payout()
            );

        } catch (Exception $e) {
            throw new PayoutCreationException(
                "failed to deserialize BitPay server response (Payout) : ".$e->getMessage());
        }

        return $payout;
    }

    /**
     * Retrieve a BitPay payout by payout id using.  The client must have been previously authorized for the payout facade.
     *
     * @param $payoutId string The id of the payout to retrieve.
     * @return Payout A BitPay Payout object.
     * @throws PayoutQueryException BitPayException class
     */
    public function getPayout(string $payoutId): Payout
    {
        try {
            $params = [];
            $params["token"] = $this->_tokenCache->getTokenByFacade(Facade::Payout);

            $responseJson = $this->_RESTcli->get("payouts/".$payoutId, $params);
        } catch (BitPayException $e) {
            throw new PayoutQueryException("failed to serialize Payout object : ".$e->getMessage(), null, null, $e->getApiCode());
        } catch (Exception $e) {
            throw new PayoutQueryException("failed to serialize Payout object : ".$e->getMessage());
        }

        try {
            $mapper = new JsonMapper();
            $payout = $mapper->map(
                json_decode($responseJson),
                new Payout()
            );

        } catch (Exception $e) {
            throw new PayoutQueryException(
                "failed to deserialize BitPay server response (Payout) : ".$e->getMessage());
        }

        return $payout;
    }

    /**
     * Retrieve a collection of BitPay payouts.
     *
     * @param $startDate string The start date to filter the Payout Batches.
     * @param $endDate string The end date to filter the Payout Batches.
     * @param $status string The status to filter the Payout Batches.
     * @param $reference string The optional reference specified at payout request creation.
     * @param $limit int Maximum results that the query will return (useful for paging results).
     * @param $offset int number of results to offset (ex. skip 10 will give you results starting *  with the 11th result).
     * @return array A list of BitPay Payout objects.
     * @throws PayoutQueryException
     */
    public function getPayouts(
        string $startDate = null, 
        string $endDate = null, 
        string $status = null, 
        string $reference = null, 
        int $limit = null, 
        int $offset = null): array
    {
        try {
            $params = [];
            $params["token"] = $this->_tokenCache->getTokenByFacade(Facade::Payout);
            if ($startDate) {
                $params["startDate"] = $startDate;
            }
            if ($endDate) {
                $params["endDate"] = $endDate;
            }
            if ($status) {
                $params["status"] = $status;
            }
            if ($reference) {
                $params["reference"] = $reference;
            }
            if ($limit) {
                $params["limit"] = $limit;
            }
            if ($offset) {
                $params["offset"] = $offset;
            }

            $responseJson = $this->_RESTcli->get("payouts", $params);
        } catch (BitPayException $e) {
            throw new PayoutQueryException("failed to serialize Payout object : ".$e->getMessage(), null, null, $e->getApiCode());
        } catch (Exception $e) {
            throw new PayoutQueryException("failed to serialize Payout object : ".$e->getMessage());
        }

        try {
            $mapper = new JsonMapper();
            $payouts = $mapper->mapArray(
                json_decode($responseJson),
                [],
                'BitPaySDK\Model\Payout\Payout'
            );

        } catch (Exception $e) {
            throw new PayoutQueryException(
                "failed to deserialize BitPay server response (Payout) : ".$e->getMessage());
        }

        return $payouts;
    }

    /**
     * Cancel a BitPay Payout.
     *
     * @param $payoutId string The id of the payout to cancel.
     * @return Payout A BitPay generated Payout object.
     * @throws PayoutCancellationException BitPayException class
     */
    public function cancelPayout(string $payoutId): bool
    {
        try {
            $params = [];
            $params["token"] = $this->_tokenCache->getTokenByFacade(Facade::Payout);

            $responseJson = $this->_RESTcli->delete("payouts/".$payoutId, $params);
        } catch (BitPayException $e) {
            throw new PayoutCancellationException("failed to serialize Payout object : ".$e->getMessage(), null, null, $e->getApiCode());
        } catch (Exception $e) {
            throw new PayoutCancellationException("failed to serialize Payout object : ".$e->getMessage());
        }

        try {
            $result = strtolower(json_decode($responseJson)->status) == "success";

        } catch (Exception $e) {
            throw new PayoutCancellationException(
                "failed to deserialize BitPay server response (Payout) : ".$e->getMessage());
        }

        return $result;
    }

    /**
     * Notify BitPay Payout.
     *
     * @param  $payoutId string The id of the Payout to notify.
     * @return array A list of BitPay Payout objects.
     * @throws PayoutNotificationException BitPayException class
     */
    public function requestPayoutNotification(string $payoutId): bool
    {
        try {
            $content = [];
            $content["token"] = $this->_tokenCache->getTokenByFacade(Facade::Payout);

            $responseJson = $this->_RESTcli->post("payouts/".$payoutId."/notifications", $content);
        } catch (BitPayException $e) {
            throw new PayoutNotificationException("failed to serialize Payout object : ".$e->getMessage(), null, null, $e->getApiCode());
        } catch (Exception $e) {
            throw new PayoutNotificationException("failed to serialize Payout object : ".$e->getMessage());
        }

        try {
            $result = strtolower(json_decode($responseJson)->status) == "success";

        } catch (Exception $e) {
            throw new PayoutNotificationException(
                "failed to deserialize BitPay server response (Payout) : ".$e->getMessage());
        }

        return $result;
    }

    /**
     * Submit a BitPay Payout batch.
     *
     * @param $batch PayoutBatch A PayoutBatch object with request parameters defined.
     * @return PayoutBatch A PayoutBatch BitPay generated PayoutBatch object.
     * @throws PayoutBatchCreationException PayoutBatchCreationException class
     */
    public function submitPayoutBatch(PayoutBatch $batch): PayoutBatch
    {
        try {
            $batch->setToken($this->_tokenCache->getTokenByFacade(Facade::Payout));

            $precision = $this->getCurrencyInfo($batch->getCurrency())->precision ?? 2;
            $batch->formatAmount($precision);

            $responseJson = $this->_RESTcli->post("payoutBatches", $batch->toArray());
        } catch (BitPayException $e) {
            throw new PayoutBatchCreationException("failed to serialize PayoutBatch object : ".$e->getMessage(), null, null, $e->getApiCode());
        } catch (Exception $e) {
            throw new PayoutBatchCreationException("failed to serialize PayoutBatch object : ".$e->getMessage());
        }

        try {
            $mapper = new JsonMapper();
            $batch = $mapper->map(
                json_decode($responseJson),
                new PayoutBatch()
            );

        } catch (Exception $e) {
            throw new PayoutBatchCreationException(
                "failed to deserialize BitPay server response (PayoutBatch) : ".$e->getMessage());
        }

        return $batch;
    }

    /**
     * Retrieve a BitPay payout batch by batch id using.  The client must have been previously authorized for the
     * payout facade.
     *
     * @param $payoutBatchId string The id of the batch to retrieve.
     * @return PayoutBatch A BitPay PayoutBatch object.
     * @throws PayoutBatchQueryException PayoutBatchQueryException class
     */
    public function getPayoutBatch(string $payoutBatchId): PayoutBatch
    {
        try {
            $params = [];
            $params["token"] = $this->_tokenCache->getTokenByFacade(Facade::Payout);

            $responseJson = $this->_RESTcli->get("payoutBatches/".$payoutBatchId, $params);
        } catch (BitPayException $e) {
            throw new PayoutBatchQueryException("failed to serialize PayoutBatch object : ".$e->getMessage(), null, null, $e->getApiCode());
        } catch (Exception $e) {
            throw new PayoutBatchQueryException("failed to serialize PayoutBatch object : ".$e->getMessage());
        }

        try {
            $mapper = new JsonMapper();
            $batch = $mapper->map(
                json_decode($responseJson),
                new PayoutBatch()
            );

        } catch (Exception $e) {
            throw new PayoutBatchQueryException(
                "failed to deserialize BitPay server response (PayoutBatch) : ".$e->getMessage());
        }

        return $batch;
    }


    /**
     * Retrieve a collection of BitPay payout batches.
     *
     * @param $startDate string The start date to filter the Payout Batches.
     * @param $endDate string The end date to filter the Payout Batches.
     * @param $status string The status to filter the Payout Batches.
     * @param $limit int Maximum results that the query will return (useful for paging results).
     * @param $offset int The offset to filter the Payout Batches.
     * @return array A list of BitPay PayoutBatch objects.
     * @throws PayoutBatchQueryException
     */
    public function getPayoutBatches(
        string $startDate = null, 
        string $endDate = null, 
        string $status = null, 
        int $limit = null, 
        int $offset = null): array
    {
        try {
            $params = [];
            $params["token"] = $this->_tokenCache->getTokenByFacade(Facade::Payout);
            if ($startDate) {
                $params["startDate"] = $startDate;
            }
            if ($endDate) {
                $params["endDate"] = $endDate;
            }
            if ($status) {
                $params["status"] = $status;
            }
            if ($limit) {
                $params["limit"] = $limit;
            }
            if ($offset) {
                $params["offset"] = $offset;
            }

            $responseJson = $this->_RESTcli->get("payoutBatches", $params);
        } catch (BitPayException $e) {
            throw new PayoutBatchQueryException("failed to serialize PayoutBatch object : ".$e->getMessage(), null, null, $e->getApiCode());
        } catch (Exception $e) {
            throw new PayoutBatchQueryException("failed to serialize PayoutBatch object : ".$e->getMessage());
        }

        try {
            $mapper = new JsonMapper();
            $batches = $mapper->mapArray(
                json_decode($responseJson),
                [],
                'BitPaySDK\Model\Payout\PayoutBatch'
            );

        } catch (Exception $e) {
            throw new PayoutBatchQueryException(
                "failed to deserialize BitPay server response (PayoutBatch) : ".$e->getMessage());
        }

        return $batches;
    }

    /**
     * Cancel a BitPay Payout batch.
     *
     * @param $batchId string The id of the batch to cancel.
     * @return PayoutBatch A BitPay generated PayoutBatch object.
     * @throws PayoutBatchCancellationException PayoutBatchCancellationException class
     */
    public function cancelPayoutBatch(string $payoutBatchId): bool
    {
        try {
            $params = [];
            $params["token"] = $this->_tokenCache->getTokenByFacade(Facade::Payout);

            $responseJson = $this->_RESTcli->delete("payoutBatches/".$payoutBatchId, $params);
        } catch (BitPayException $e) {
            throw new PayoutBatchCancellationException("failed to serialize PayoutBatch object : ".$e->getMessage(), null, null, $e->getApiCode());
        } catch (Exception $e) {
            throw new PayoutBatchCancellationException("failed to serialize PayoutBatch object : ".$e->getMessage());
        }

        try {
            $result = strtolower(json_decode($responseJson)->status) == "success";

        } catch (Exception $e) {
            throw new PayoutBatchCancellationException(
                "failed to deserialize BitPay server response (PayoutBatch) : ".$e->getMessage());
        }

        return $result;
    }

    /**
     * Notify BitPay PayoutBatch.
     *
     * @param  $payoutId string The id of the PayoutBatch to notify.
     * @return array A list of BitPay PayoutBatch objects.
     * @throws PayoutBatchNotificationException PayoutBatchNotificationException class
     */
    public function requestPayoutBatchNotification(string $payoutBatchId): bool
    {
        try {
            $content = [];
            $content["token"] = $this->_tokenCache->getTokenByFacade(Facade::Payout);

            $responseJson = $this->_RESTcli->post("payoutBatches/".$payoutBatchId."/notifications", $content);
        } catch (BitPayException $e) {
            throw new PayoutBatchNotificationException("failed to serialize PayoutBatch object : ".$e->getMessage(), null, null, $e->getApiCode());
        } catch (Exception $e) {
            throw new PayoutBatchNotificationException("failed to serialize PayoutBatch object : ".$e->getMessage());
        }

        try {
            $result = strtolower(json_decode($responseJson)->status) == "success";

        } catch (Exception $e) {
            throw new PayoutBatchNotificationException(
                "failed to deserialize BitPay server response (PayoutBatch) : ".$e->getMessage());
        }

        return $result;
    }

    /**
     * Retrieves settlement reports for the calling merchant filtered by query.
     * The `limit` and `offset` parameters
     * specify pages for large query sets.
     *
     * @param $currency  string The three digit currency string for the ledger to retrieve.
     * @param $dateStart string The start date for the query.
     * @param $dateEnd   string The end date for the query.
     * @param $status    string Can be `processing`, `completed`, or `failed`.
     * @param $limit     int Maximum number of settlements to retrieve.
     * @param $offset    int Offset for paging.
     * @return array A list of BitPay Settlement objects.
     * @throws BitPayException BitPayException class
     */
    public function getSettlements(
        string $currency,
        string $dateStart,
        string $dateEnd,
        string $status = null,
        int $limit = null,
        int $offset = null
    ): array {
        try {
            $status = $status != null ? $status : "";
            $limit = $limit != null ? $limit : 100;
            $offset = $offset != null ? $offset : 0;

            $params = [];
            $params["token"] = $this->_tokenCache->getTokenByFacade(Facade::Merchant);
            $params["dateStart"] = $dateStart;
            $params["dateEnd"] = $dateEnd;
            $params["currency"] = $currency;
            $params["status"] = $status;
            $params["limit"] = (string)$limit;
            $params["offset"] = (string)$offset;

            $responseJson = $this->_RESTcli->get("settlements", $params);
        } catch (BitPayException $e) {
            throw new SettlementQueryException("failed to serialize Settlement object : ".$e->getMessage(), null, null, $e->getApiCode());
        } catch (Exception $e) {
            throw new SettlementQueryException("failed to serialize Settlement object : ".$e->getMessage());
        }

        try {
            $mapper = new JsonMapper();
            $settlements = $mapper->mapArray(
                json_decode($responseJson),
                [],
                'BitPaySDK\Model\Settlement\Settlement'
            );

        } catch (Exception $e) {
            throw new SettlementQueryException(
                "failed to deserialize BitPay server response (Settlement) : ".$e->getMessage());
        }

        return $settlements;
    }

    /**
     * Retrieves a summary of the specified settlement.
     *
     * @param $settlementId string Settlement Id.
     * @return Settlement A BitPay Settlement object.
     * @throws BitPayException BitPayException class
     */
    public function getSettlement(string $settlementId): Settlement
    {
        try {
            $params = [];
            $params["token"] = $this->_tokenCache->getTokenByFacade(Facade::Merchant);

            $responseJson = $this->_RESTcli->get("settlements/".$settlementId, $params);
        } catch (BitPayException $e) {
            throw new SettlementQueryException("failed to serialize Settlement object : ".$e->getMessage(), null, null, $e->getApiCode());
        } catch (Exception $e) {
            throw new SettlementQueryException("failed to serialize Settlement object : ".$e->getMessage());
        }

        try {
            $mapper = new JsonMapper();
            $settlement = $mapper->map(
                json_decode($responseJson),
                new Settlement()
            );

        } catch (Exception $e) {
            throw new SettlementQueryException(
                "failed to deserialize BitPay server response (Settlement) : ".$e->getMessage());
        }

        return $settlement;
    }

    /**
     * Gets a detailed reconciliation report of the activity within the settlement period.
     *
     * @param $settlement Settlement to generate report for.
     * @return Settlement A detailed BitPay Settlement object.
     * @throws BitPayException BitPayException class
     */
    public function getSettlementReconciliationReport(Settlement $settlement): Settlement
    {
        try {
            $params = [];
            $params["token"] = $settlement->getToken();

            $responseJson = $this->_RESTcli->get("settlements/".$settlement->getId()."/reconciliationReport", $params);
        } catch (BitPayException $e) {
            throw new SettlementQueryException("failed to serialize Reconciliation Report object : ".$e->getMessage(), null, null, $e->getApiCode());
        } catch (Exception $e) {
            throw new SettlementQueryException("failed to serialize Reconciliation Report object : ".$e->getMessage());
        }

        try {
            $mapper = new JsonMapper();
            $reconciliationReport = $mapper->map(
                json_decode($responseJson),
                new Settlement()
            );

        } catch (Exception $e) {
            throw new SettlementQueryException(
                "failed to deserialize BitPay server response (Reconciliation Report) : ".$e->getMessage());
        }

        return $reconciliationReport;
    }

    /**
     * Create a BitPay Subscription.
     *
     * @param $subscription Subscription A Subscription object with request parameters defined.
     * @return Subscription A BitPay generated Subscription object.
     * @throws BitPayException BitPayException class
     */
    public function createSubscription(Subscription $subscription): Subscription
    {
        try {
            $subscription->setToken($this->_tokenCache->getTokenByFacade(Facade::Merchant));

            $responseJson = $this->_RESTcli->post("subscriptions", $subscription->toArray());
        } catch (BitPayException $e) {
            throw new SubscriptionCreationException("failed to serialize Subscription object : ".$e->getMessage(), null, null, $e->getApiCode());
        } catch (Exception $e) {
            throw new SubscriptionCreationException("failed to serialize Subscription object : ".$e->getMessage());
        }

        try {
            $mapper = new JsonMapper();
            $subscription = $mapper->map(
                json_decode($responseJson),
                new Subscription()
            );

        } catch (Exception $e) {
            throw new SubscriptionCreationException(
                "failed to deserialize BitPay server response (Subscription) : ".$e->getMessage());
        }

        return $subscription;
    }

    /**
     * Retrieve a BitPay subscription by subscription id using the specified facade.
     *
     * @param $subscriptionId string The id of the subscription to retrieve.
     * @return Subscription A BitPay Subscription object.
     * @throws BitPayException BitPayException class
     */
    public function getSubscription(string $subscriptionId): Subscription
    {

        try {
            $params = [];
            $params["token"] = $this->_tokenCache->getTokenByFacade(Facade::Merchant);

            $responseJson = $this->_RESTcli->get("subscriptions/".$subscriptionId, $params);
        } catch (BitPayException $e) {
            throw new SubscriptionQueryException("failed to serialize Subscription object : ".$e->getMessage(), null, null, $e->getApiCode());
        } catch (Exception $e) {
            throw new SubscriptionQueryException("failed to serialize Subscription object : ".$e->getMessage());
        }

        try {
            $mapper = new JsonMapper();
            $subscription = $mapper->map(
                json_decode($responseJson),
                new Subscription()
            );

        } catch (Exception $e) {
            throw new SubscriptionQueryException(
                "failed to deserialize BitPay server response (Subscription) : ".$e->getMessage());
        }

        return $subscription;
    }

    /**
     * Retrieve a collection of BitPay subscriptions.
     *
     * @param $status string|null The status to filter the subscriptions.
     * @return array A list of BitPay Subscription objects.
     * @throws BitPayException BitPayException class
     */
    public function getSubscriptions(string $status = null): array
    {
        try {
            $params = [];
            $params["token"] = $this->_tokenCache->getTokenByFacade(Facade::Merchant);
            if ($status) {
                $params["status"] = $status;
            }

            $responseJson = $this->_RESTcli->get("subscriptions", $params);
        } catch (BitPayException $e) {
            throw new SubscriptionQueryException("failed to serialize Subscription object : ".$e->getMessage(), null, null, $e->getApiCode());
        } catch (Exception $e) {
            throw new SubscriptionQueryException("failed to serialize Subscription object : ".$e->getMessage());
        }

        try {
            $mapper = new JsonMapper();
            $subscriptions = $mapper->mapArray(
                json_decode($responseJson),
                [],
                'BitPaySDK\Model\Subscription\Subscription'
            );

        } catch (Exception $e) {
            throw new SubscriptionQueryException(
                "failed to deserialize BitPay server response (Subscription) : ".$e->getMessage());
        }

        return $subscriptions;
    }

    /**
     * Update a BitPay Subscription.
     *
     * @param $subscription   Subscription A Subscription object with the parameters to update defined.
     * @param $subscriptionId string $subscriptionIdThe Id of the Subscription to update.
     * @return Subscription An updated Subscription object.
     * @throws BitPayException BitPayException class
     */
    public function updateSubscription(Subscription $subscription, string $subscriptionId): Subscription
    {
        try {
            $subscriptionToken = $this->getSubscription($subscription->getId())->getToken();
            $subscription->setToken($subscriptionToken);

            $responseJson = $this->_RESTcli->update("subscriptions/".$subscriptionId, $subscription->toArray());
        } catch (BitPayException $e) {
            throw new SubscriptionUpdateException("failed to serialize Subscription object : ".$e->getMessage(), null, null, $e->getApiCode());
        } catch (Exception $e) {
            throw new SubscriptionUpdateException("failed to serialize Subscription object : ".$e->getMessage());
        }

        try {
            $mapper = new JsonMapper();
            $subscription = $mapper->map(
                json_decode($responseJson),
                $subscription
            );

        } catch (Exception $e) {
            throw new SubscriptionUpdateException(
                "failed to deserialize BitPay server response (Subscription) : ".$e->getMessage());
        }

        return $subscription;
    }

    /**
     * Fetch the supported currencies.
     *
     * @return array     A list of BitPay Invoice objects.
     * @throws BitPayException BitPayException class
     */
    public function getCurrencies(): array
    {
        try {
            $currencies = json_decode($this->_RESTcli->get("currencies/", null, false), false);
        } catch (BitPayException $e) {
            throw new CurrencyQueryException("failed to serialize Currency object : ".$e->getMessage(), null, null, $e->getApiCode());
        } catch (Exception $e) {
            throw new CurrencyQueryException("failed to serialize Currency object : ".$e->getMessage());
        }

        return $currencies;
    }

    ////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
    ////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
    ////////////////////////////////////////////////////////////////////////////////////////////////////////////////////

    /**
     * Builds the configuration object
     *
     * @param $privateKey       String The full path to the securely located private key or the HEX key value.
     * @param $tokens           Tokens object containing the BitPay's API tokens.
     * @param $privateKeySecret String Private Key encryption password only for key file.
     * @param $proxy            String|null An http url of a proxy to foward requests through.
     * @throws BitPayException BitPayException class
     */
    private function buildConfig($privateKey, $tokens, $privateKeySecret = null, ?string $proxy = null)
    {
        try {
            if (!file_exists($privateKey)) {
                $key = new PrivateKey("plainHex");
                $key->setHex($privateKey);
                if (!$key->isValid()) {
                    throw new BitPayException("Private Key not found/valid");
                }
                $this->_ecKey = $key;
            }
            $this->_configuration = new Config();
            $this->_configuration->setEnvironment($this->_env);

            $envConfig[$this->_env] = [
                "PrivateKeyPath"   => $privateKey,
                "PrivateKeySecret" => $privateKeySecret,
                "ApiTokens"        => $tokens,
                "Proxy"            => $proxy,
            ];

            $this->_configuration->setEnvConfig($envConfig);
        } catch (Exception $e) {
            throw new BitPayException("failed to build configuration : ".$e->getMessage());
        }
    }

    /**
     * Loads the configuration file (JSON)
     *
     * @throws BitPayException BitPayException class
     */
    public function getConfig()
    {
        try {
            $this->_configuration = new Config();
            if (!file_exists($this->_configFilePath)) {
                throw new BitPayException("Configuration file not found");
            }
            $configData = json_decode(file_get_contents($this->_configFilePath), true);

            if (!$configData) {
                $configData = Yaml::parseFile($this->_configFilePath);
            }
            $this->_configuration->setEnvironment($configData["BitPayConfiguration"]["Environment"]);
            $this->_env = $this->_configuration->getEnvironment();

            $tokens = Tokens::loadFromArray($configData["BitPayConfiguration"]["EnvConfig"][$this->_env]["ApiTokens"]);
            $privateKeyPath = $configData["BitPayConfiguration"]["EnvConfig"][$this->_env]["PrivateKeyPath"];
            $privateKeySecret = $configData["BitPayConfiguration"]["EnvConfig"][$this->_env]["PrivateKeySecret"];
            $proxy = $configData["BitPayConfiguration"]["EnvConfig"][$this->_env]["Proxy"] ?? null;

            $envConfig[$this->_env] = [
                "PrivateKeyPath"   => $privateKeyPath,
                "PrivateKeySecret" => $privateKeySecret,
                "ApiTokens"        => $tokens,
                "Proxy"            => $proxy,
            ];

            $this->_configuration->setEnvConfig($envConfig);
        } catch (Exception $e) {
            throw new BitPayException("failed to initialize BitPay Client (Config) : ".$e->getMessage());
        }
    }

    /**
     * Initialize the public/private key pair by either loading the existing one or by creating a new one.
     *
     * @throws BitPayException BitPayException class
     */
    private function initKeys()
    {
        $privateKey = $this->_configuration->getEnvConfig()[$this->_env]["PrivateKeyPath"];
        $privateKeySecret = $this->_configuration->getEnvConfig()[$this->_env]["PrivateKeySecret"];

        try {
            if (!$this->_ecKey) {
                $this->_ecKey = new PrivateKey($privateKey);
                $storageEngine = new EncryptedFilesystemStorage($privateKeySecret);
                $this->_ecKey = $storageEngine->load($privateKey);
            }
        } catch (Exception $e) {
            throw new BitPayException("failed to build configuration : ".$e->getMessage());
        }
    }

    /**
     * Initialize this object with the client name and the environment Url.
     *
     * @throws BitPayException BitPayException class
     */
    private function init()
    {
        try {
            $proxy = $this->_configuration->getEnvConfig()[$this->_env]["Proxy"] ?? null;
            $this->_RESTcli = new RESTcli($this->_env, $this->_ecKey, $proxy);
            $this->loadAccessTokens();
            $this->loadCurrencies();
        } catch (Exception $e) {
            throw new BitPayException("failed to build configuration : ".$e->getMessage());
        }
    }

    /**
     * Load tokens from configuration.
     *
     * @throws BitPayException BitPayException class
     */
    private function loadAccessTokens()
    {
        try {
            $this->clearAccessTokenCache();

            $this->_tokenCache = $this->_configuration->getEnvConfig()[$this->_env]["ApiTokens"];
        } catch (Exception $e) {
            throw new BitPayException("When trying to load the tokens : ".$e->getMessage());
        }
    }

    private function clearAccessTokenCache()
    {
        $this->_tokenCache = new Tokens();
    }

    /**
     * Load currencies info.
     *
     * @throws BitPayException BitPayException class
     */
    private function loadCurrencies()
    {
        try {
            $this->_currenciesInfo = $this->getCurrencies();
        } catch (Exception $e) {
            throw new BitPayException("When loading currencies info : ".$e->getMessage());
        }
    }

    /**
     * Gets info for specific currency.
     *
     * @param $currencyCode String Currency code for which the info will be retrieved.
     *
     * @return object|null
     */
    public function getCurrencyInfo(string $currencyCode)
    {
        foreach ($this->_currenciesInfo as $currencyInfo) {
            if ($currencyInfo->code == $currencyCode) {
                return $currencyInfo;
            }
        }

        return null;
    }
}