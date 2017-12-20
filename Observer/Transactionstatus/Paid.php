<?php

/**
 * PAYONE Magento 2 Connector is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * PAYONE Magento 2 Connector is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with PAYONE Magento 2 Connector. If not, see <http://www.gnu.org/licenses/>.
 *
 * PHP version 5
 *
 * @category  Payone
 * @package   Payone_Magento2_Plugin
 * @author    FATCHIP GmbH <support@fatchip.de>
 * @copyright 2003 - 2016 Payone GmbH
 * @license   <http://www.gnu.org/licenses/> GNU Lesser General Public License
 * @link      http://www.payone.de
 */

namespace Payone\Core\Observer\Transactionstatus;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Payone\Core\Helper\Base;
use Payone\Core\Model\PayoneConfig;

/**
 * Event observer for Transactionstatus paid
 */
class Paid implements ObserverInterface
{
    /**
     * InvoiceService object
     *
     * @var InvoiceService
     */
    protected $invoiceService;

    protected $invoiceRepository;
    protected $searchCriteriaBuilder;

    /**
     * InvoiceSender object
     *
     * @var InvoiceSender
     */
    protected $invoiceSender;

    /**
     * Payone base helper
     *
     * @var Base
     */
    protected $baseHelper;

    /**
     * Constructor.
     *
     * @param InvoiceService $invoiceService
     * @param InvoiceSender  $invoiceSender
     * @param Base           $baseHelper
     */

    public function __construct(
        \Magento\Sales\Model\Service\InvoiceService $invoiceService,
        InvoiceSender $invoiceSender,
        \Magento\Sales\Api\InvoiceRepositoryInterface $invoiceRepository,
        \Magento\Framework\Api\SearchCriteriaBuilder $searchCriteriaBuilder,
        Base $baseHelper
    ) {
        $this->invoiceService = $invoiceService;
        $this->invoiceSender = $invoiceSender;
        $this->baseHelper = $baseHelper;
        $this->invoiceRepository = $invoiceRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;

    }

    /**
     * Generate an invoice for the order to mark the order as paid
     *
     * @param  Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        /* @var $oOrder Order */
        $oOrder = $observer->getOrder();

        // order is not guaranteed to exist if using transaction status forwarding
        // advance payment should not create an invoice
        if (null === $oOrder || $oOrder->getPayment()->getMethodInstance()->getCode() == PayoneConfig::METHOD_ADVANCE_PAYMENT){
            return;
        }

//          if invoice already created
        $oInvoice  = $this->getOpenInvoice($oOrder);
        if ($oInvoice) {
            $oInvoice->setForcePay(true);       // for pre-authorization
            $oInvoice->pay();
            $oInvoice->setTransactionId($oOrder->getPayment()->getLastTransId());
        } else {
            $oInvoice = $this->invoiceService->prepareInvoice($oOrder);
            $oInvoice->setRequestedCaptureCase(Invoice::CAPTURE_OFFLINE);
            $oInvoice->setTransactionId($oOrder->getPayment()->getLastTransId());
            $oInvoice->register();
        }

        $oInvoice->save();
        $oInvoice->getOrder()->save();  // not use $order->save() as this comes from event and is not updated during $oInvoice->pay() => all changes would be lost

//        if ($this->baseHelper->getConfigParam('send_invoice_email', 'emails')) {
//            $this->invoiceSender->send($oInvoice);
//        }
    }

    /**
     * Get open invoice for order
     * @param Order $order
     * @return \Magento\Sales\Api\Data\InvoiceInterface|mixed|null
     */
    public function getOpenInvoice(Order $order)
    {
        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('order_id', $order->getId(), 'eq')
            ->addFilter('state', Invoice::STATE_OPEN, 'eq')
            ->addFilter('grand_total', $order->getGrandTotal(), 'eq')
            ->create()
        ;

        $invoices = $this->invoiceRepository->getList($searchCriteria)->getItems();
        if (count($invoices)) {
            return array_pop($invoices);
        }

        return null;
    }
}
