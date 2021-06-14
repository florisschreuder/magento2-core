<?php
/**
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is provided with Magento in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 *
 * Copyright © 2021 MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 *
 */

declare(strict_types=1);

namespace MultiSafepay\ConnectCore\Gateway\Request\Builder;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Payment\Gateway\Request\BuilderInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order;
use MultiSafepay\ConnectCore\Model\Ui\Gateway\BankTransferConfigProvider;
use MultiSafepay\ConnectCore\Service\EmailSender;
use MultiSafepay\ConnectCore\Util\OrderStatusUtil;

class RedirectTransactionBuilder implements BuilderInterface
{
    /**
     * @var OrderStatusUtil
     */
    private $orderStatusUtil;

    /**
     * @var State
     */
    private $state;

    /**
     * @var EmailSender
     */
    private $emailSender;

    /**
     * RedirectTransactionBuilder constructor.
     *
     * @param EmailSender $emailSender
     * @param OrderStatusUtil $orderStatusUtil
     * @param State $state
     */
    public function __construct(
        EmailSender $emailSender,
        OrderStatusUtil $orderStatusUtil,
        State $state
    ) {
        $this->state = $state;
        $this->emailSender = $emailSender;
        $this->orderStatusUtil = $orderStatusUtil;
    }

    /**
     * @inheritDoc
     * @throws LocalizedException
     */
    public function build(array $buildSubject): array
    {
        $stateObject = $buildSubject['stateObject'];

        $paymentDataObject = SubjectReader::readPayment($buildSubject);
        $payment = $paymentDataObject->getPayment();
        $order = $payment->getOrder();

        $paymentMethod = $payment->getMethod() ?? $payment->getMethodInstance()->getCode();

        $orderStateAndStatus = $this->getOrderStateAndStatus($order, $paymentMethod);

        $stateObject->setState($orderStateAndStatus[0]);
        $stateObject->setStatus($orderStateAndStatus[1]);

        // Early return on backend order
        if ($this->state->getAreaCode() === Area::AREA_ADMINHTML) {
            return [];
        }

        // If not backend order, check when order confirmation e-mail needs to be sent
        if (!$this->emailSender->checkOrderConfirmationBeforeTransaction()) {
            $stateObject->setIsNotified(false);
            $order->setCanSendNewEmailFlag(false);
        }

        return [];
    }

    /**
     * @param OrderInterface $order
     * @param string $paymentMethod
     * @return array
     */
    private function getOrderStateAndStatus(OrderInterface $order, string $paymentMethod): array
    {
        if ($paymentMethod === BankTransferConfigProvider::CODE) {
            return [Order::STATE_NEW, $this->orderStatusUtil->getPendingStatus($order)];
        }

        return [Order::STATE_PENDING_PAYMENT, $this->orderStatusUtil->getPendingPaymentStatus($order)];
    }
}
