<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrdersProduct;

/** Отвечает за работ с продуктами */
class ProductService
{
    /**
     * Создать или обновить заказанный продукт
     *
     * @param Order $order
     * @param array $product_data
     * @param int   $pp_id
     * @return OrdersProduct
     * @noinspection PhpUndefinedClassInspection
     */
    public function productOrdered(Order $order, array $product_data, int $pp_id): OrdersProduct
    {
        $product = $this->getOrderProduct($order->id, $product_data['id'], $pp_id) ?? new OrdersProduct();
        // всё что ниже можно заменить на $product::update, но иногда магия может подвести
        $product->pp_id = $pp_id;
        $product->order_id = $order->order_id;
        $product->parent_id = $order->id;
        $product->datetime = $order->datetime;
        $product->partner_id = $order->partner_id;
        $product->offer_id = $order->offer_id;
        $product->link_id = $order->link_id;
        $product->web_id = $order->web_id;
        $product->click_id = $order->click_id;
        $product->pixel_id = $order->pixel_id;
        $product->product_id = $product_data['id'];
        $product->price = $product_data['price'];
        $product->category = $product_data['category'] ?? null;
        $product->quantity = $product_data['quantity'] ?? 1;
        $product->product_name = $this->getProductSlug($product_data);
        $product->total = $product->price * $product->quantity;
        $product->amount = 0;
        $product->amount_advert = 0;
        $product->fee_advert = 0;
        $product->save();
        logger()->debug('Сохранен продукт: ' . $product->product_name);
        return $product;
    }

    /**
     * @param int $order_id
     * @param int $product_id
     * @param int $pp_id
     * @return OrdersProduct | null
     */
    private function getOrderProduct(int $order_id, int $product_id, int $pp_id): ?OrdersProduct
    {
        return OrdersProduct::where('pp_id', '=', $pp_id)
            ->where('order_id', '=', $order_id)
            ->where('product_id', '=', $product_id)
            ->first();
    }

    /**
     * @param array $product_data
     * @return string
     */
    private function getProductSlug(array $product_data): string
    {
        return trim(($product_data['name'] ?? '') . ' ' . ($product_data['variant'] ?? ''));
    }
}