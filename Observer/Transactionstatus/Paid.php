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
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;

/**
 * Event observer for Transactionstatus paid
 */
class Paid implements ObserverInterface
{
    /**
     * InvoiceService object
     *
     * @var \Magento\Sales\Model\Service\InvoiceService
     */
    protected $invoiceService;

    protected $invoiceRepository;
    protected $searchCriteriaBuilder;

    /**
     * Constructor.
     *
     * @param \Magento\Sales\Model\Service\InvoiceService $invoiceService
     */
    public function __construct(
        \Magento\Sales\Model\Service\InvoiceService $invoiceService,
        \Magento\Sales\Api\InvoiceRepositoryInterface $nvoiceRepository,
        \Magento\Framework\Api\SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
        $this->invoiceService = $invoiceService;
        $this->invoiceRepository = $nvoiceRepository;
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
        if (null === $oOrder){
            return;
        }

//          if invoice already created
        $oInvoice  = $this->getOpenInvoice($oOrder);
        if ($oInvoice) {
            $oInvoice->pay();
            $oInvoice->setForcePay(true);       // for pre-authorization
            $oInvoice->setTransactionId($oOrder->getPayment()->getLastTransId());
        } else {
            $oInvoice = $this->invoiceService->prepareInvoice($oOrder);
            $oInvoice->setRequestedCaptureCase(Invoice::CAPTURE_OFFLINE);
            $oInvoice->setTransactionId($oOrder->getPayment()->getLastTransId());
            $oInvoice->register();
        }

        $oInvoice->save();
        $oInvoice->getOrder()->save();  // not use $order->save() as this comes from event and is not updated during $oInvoice->pay() => all changes would be lost
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
        if (count($invoices) === 1) {
            return array_pop($invoices);
        }

//         0 or more than one invoices found
        return null;
    }
}
