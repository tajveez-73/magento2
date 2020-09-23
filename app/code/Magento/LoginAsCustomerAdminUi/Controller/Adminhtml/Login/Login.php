<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\LoginAsCustomerAdminUi\Controller\Adminhtml\Login;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Auth\Session;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Controller\Result\Json as JsonResult;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Url;
use Magento\LoginAsCustomerApi\Api\ConfigInterface;
use Magento\LoginAsCustomerApi\Api\Data\AuthenticationDataInterface;
use Magento\LoginAsCustomerApi\Api\Data\AuthenticationDataInterfaceFactory;
use Magento\LoginAsCustomerApi\Api\DeleteAuthenticationDataForUserInterface;
use Magento\LoginAsCustomerApi\Api\IsLoginAsCustomerEnabledForCustomerInterface;
use Magento\LoginAsCustomerApi\Api\SaveAuthenticationDataInterface;
use Magento\LoginAsCustomerApi\Api\SetLoggedAsCustomerCustomerIdInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Login as customer action
 * Generate secret key and forward to the storefront action
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class Login extends Action implements HttpPostActionInterface
{
    /**
     * Authorization level of a basic admin session
     *
     * @see _isAllowed()
     */
    const ADMIN_RESOURCE = 'Magento_LoginAsCustomer::login';

    /**
     * @var Session
     */
    private $authSession;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var CustomerRepositoryInterface
     */
    private $customerRepository;

    /**
     * @var ConfigInterface
     */
    private $config;

    /**
     * @var AuthenticationDataInterfaceFactory
     */
    private $authenticationDataFactory;

    /**
     * @var SaveAuthenticationDataInterface
     */
    private $saveAuthenticationData;

    /**
     * @var DeleteAuthenticationDataForUserInterface
     */
    private $deleteAuthenticationDataForUser;

    /**
     * @var Url
     */
    private $url;

    /**
     * @var SetLoggedAsCustomerCustomerIdInterface
     */
    private $setLoggedAsCustomerCustomerId;

    /**
     * @var IsLoginAsCustomerEnabledForCustomerInterface
     */
    private $isLoginAsCustomerEnabled;

    /**
     * @param Context $context
     * @param Session $authSession
     * @param StoreManagerInterface $storeManager
     * @param CustomerRepositoryInterface $customerRepository
     * @param ConfigInterface $config
     * @param AuthenticationDataInterfaceFactory $authenticationDataFactory
     * @param SaveAuthenticationDataInterface $saveAuthenticationData
     * @param DeleteAuthenticationDataForUserInterface $deleteAuthenticationDataForUser
     * @param Url $url
     * @param SetLoggedAsCustomerCustomerIdInterface $setLoggedAsCustomerCustomerId
     * @param IsLoginAsCustomerEnabledForCustomerInterface $isLoginAsCustomerEnabled
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        Context $context,
        Session $authSession,
        StoreManagerInterface $storeManager,
        CustomerRepositoryInterface $customerRepository,
        ConfigInterface $config,
        AuthenticationDataInterfaceFactory $authenticationDataFactory,
        SaveAuthenticationDataInterface $saveAuthenticationData,
        DeleteAuthenticationDataForUserInterface $deleteAuthenticationDataForUser,
        Url $url,
        ?SetLoggedAsCustomerCustomerIdInterface $setLoggedAsCustomerCustomerId = null,
        ?IsLoginAsCustomerEnabledForCustomerInterface $isLoginAsCustomerEnabled = null
    ) {
        parent::__construct($context);

        $this->authSession = $authSession;
        $this->storeManager = $storeManager;
        $this->customerRepository = $customerRepository;
        $this->config = $config;
        $this->authenticationDataFactory = $authenticationDataFactory;
        $this->saveAuthenticationData = $saveAuthenticationData;
        $this->deleteAuthenticationDataForUser = $deleteAuthenticationDataForUser;
        $this->url = $url;
        $this->setLoggedAsCustomerCustomerId = $setLoggedAsCustomerCustomerId
            ?? ObjectManager::getInstance()->get(SetLoggedAsCustomerCustomerIdInterface::class);
        $this->isLoginAsCustomerEnabled = $isLoginAsCustomerEnabled
            ?? ObjectManager::getInstance()->get(IsLoginAsCustomerEnabledForCustomerInterface::class);
    }

    /**
     * Login as customer
     *
     * @return ResultInterface
     * @throws NoSuchEntityException
     * @throws LocalizedException
     */
    public function execute(): ResultInterface
    {
        $messages = [];

        $customerId = (int)$this->_request->getParam('customer_id');
        if (!$customerId) {
            $customerId = (int)$this->_request->getParam('entity_id');
        }

        $isLoginAsCustomerEnabled = $this->isLoginAsCustomerEnabled->execute($customerId);
        if (!$isLoginAsCustomerEnabled->isEnabled()) {
            foreach ($isLoginAsCustomerEnabled->getMessages() as $message) {
                $this->messageManager->addErrorMessage(__($message));
            }

            return $this->prepareJsonResult($messages);
        }

        try {
            $customer = $this->customerRepository->getById($customerId);
        } catch (NoSuchEntityException $e) {
            $messages[] = __('Customer with this ID no longer exists.');
            return $this->prepareJsonResult($messages);
        }

        if ($this->config->isStoreManualChoiceEnabled()) {
            $storeId = (int)$this->_request->getParam('store_id');
            if (empty($storeId)) {
                $messages[] = __('Please select a Store View to login in.');
                return $this->prepareJsonResult($messages);
            }
        } else {
            $storeId = (int)$customer->getStoreId();
        }

        $adminUser = $this->authSession->getUser();
        $userId = (int)$adminUser->getId();

        /** @var AuthenticationDataInterface $authenticationData */
        $authenticationData = $this->authenticationDataFactory->create(
            [
                'customerId' => $customerId,
                'adminId' => $userId,
                'extensionAttributes' => null,
            ]
        );

        $this->deleteAuthenticationDataForUser->execute($userId);
        $secret = $this->saveAuthenticationData->execute($authenticationData);
        $this->setLoggedAsCustomerCustomerId->execute($customerId);

        $redirectUrl = $this->getLoginProceedRedirectUrl($secret, $storeId);

        return $this->prepareJsonResult($messages, $redirectUrl);
    }

    /**
     * Get login proceed redirect url
     *
     * @param string $secret
     * @param int $storeId
     * @return string
     * @throws NoSuchEntityException
     */
    private function getLoginProceedRedirectUrl(string $secret, int $storeId): string
    {
        $store = $this->storeManager->getStore($storeId);

        return $this->url
            ->setScope($store)
            ->getUrl('loginascustomer/login/index', ['secret' => $secret, '_nosid' => true]);
    }

    /**
     * Prepare JSON result
     *
     * @param array $messages
     * @param string|null $redirectUrl
     * @return JsonResult
     */
    private function prepareJsonResult(array $messages, ?string $redirectUrl = null)
    {
        /** @var JsonResult $jsonResult */
        $jsonResult = $this->resultFactory->create(ResultFactory::TYPE_JSON);

        $jsonResult->setData([
            'redirectUrl' => $redirectUrl,
            'messages' => $messages,
        ]);

        return $jsonResult;
    }
}
