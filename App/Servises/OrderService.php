<?php

namespace App\Services;

use Purchase;
use App\Models\PixelLog;
use App\Models\Client;
use App\Models\Link;
use App\Models\Order;

class OrderService
{
    /**
     * @var PixelLog
     */
    private $pixel_log;
    /**
     * @var Link
     */
    private $link;
    /**
     * @var Client
     */
    private $client;

    /**
     * OrderService constructor.
     */
    public function __construct(PixelLog $pixel_log, Link $link, Client $client )
    {
        $this->pixel_log = $pixel_log;
        $this->link = $link;
        $this->client = $client;
    }

    /**
     * @param Purchase $purchase
     * @return Order
     */
    public function createOrUpdateOrder(Purchase $purchase): Order
    {
        logger()->debug('Найдено продуктов: ' . $purchase->getProductsCount());
        $order_id = $purchase->getOrderId();
        if (!$order = $this->getOrder($order_id)) {
            logger()->debug('Заказ №' . $order_id . ' не существует, создаем');
            $order = $this->createNewOrder($order_id);
        }
        logger()->debug('Заказ №' . $order_id . ' существует, обновляем');
        return $this->updateOrder($order, $purchase->getProducts());
    }

    /**
     * @param int $order_id
     * @return Order
     */
    private function createNewOrder(int $order_id): Order
    {
        $order = new Order(); // можно через Order::create([]); при желании
        $order->pp_id = $this->pixel_log->pp_id;
        $order->order_id = $order_id;
        $order->status = 'new';
        return $order;
    }

    /**
     * @param Order $order
     * @param array $products
     * @return Order
     */
    private function updateOrder(Order $order, array $products): Order
    {
        // можно через Order::update([]); при желании
        $order->pixel_id = $this->pixel_log->id;
        $order->datetime = $this->pixel_log->created_at;
        $order->partner_id = $this->link->partner_id;
        $order->link_id = $this->link->id;
        $order->offer_id = $this->link->offer_id;
        $order->client_id = $this->client->id;
        $order->click_id = $this->pixel_log->getClickId();
        $order->web_id = $this->pixel_log->getClickId();
        $order->gross_amount = 0;
        foreach ($products as $product_data) {
            $order->gross_amount += $this->calcGrossAmount($product_data);
        }
        $order->cnt_products = count($products);
        $order->save();
        return $order;
    }

    /**
     * @param int $order_id
     * @return Order
     */
    private function getOrder(int $order_id): ?Order
    {
        return Order::where('pp_id', '=', $this->pixel_log->pp_id)
            ->where('order_id', '=', $order_id)
            ->first();
    }

    /**
     * @param array $product_data
     * @return float|int
     */
    private function calcGrossAmount(array $product_data)
    {
        return $product_data['price'] * ($product_data['quantity'] ?? 1);
    }
}