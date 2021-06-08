<?php

/**
 * ЗАДАЧА СОИСКАТЕЛЮ
 *
 * Применяя принципы SOLID и заветы чистого кода
 * 1) Отрефакторить метод parseDataLayerEvent
 *
 * Полученный результат должен соответствовать DRY, KISS
 * Очевидно что рефакторинг абстрактный и как-то запускаться/тестироваться не должнен.
 * Важно понимание говнокодинка и правил написания чистого кода.
 *
 * 2) Рассказать о проблемах данного класса в частности и о подходе который привел к его появлению в общем.
 */

namespace App\Processors;

use App\Models\PixelLog;
use App\Models\Click;
use App\Models\Client;
use App\Models\Link;
use App\Models\Order;
use App\Models\OrdersProduct;
use App\Services\OrderService;
use App\Services\ProductService;
use Exception;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Purchase;
use Throwable;
use const JSON_PRETTY_PRINT;
use const JSON_UNESCAPED_UNICODE;

class PixelLogProcessor
{
    protected $pixel_log;
    protected $client;
    protected $link;

    public function __construct(PixelLog $pixel_log)
    {
        $this->pixel_log = $pixel_log;
    }

    public function process()
    {
        try {
            // Получаем новое значение для поля is_valid
            // Валидация записи. В случае ошибки - выдаст Exception
            $this->pixel_log->is_valid = $this->pixel_log->isDataValid();

            // Записываем client_id
            $this->parseClientId();

            // Получаем модель ссылки
            $this->parseLink();

            // Проверяем, если эта запись - клик - обрабатываем
            $this->parseClick();

            // А если это продажа - тоже обрабатываем!
            $this->parsePurchase();

            $this->pixel_log->status = null;
        } catch (ValidationException $e) {
            $this->pixel_log->status = json_encode($e->errors(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            logger()->warning('e', $e->errors());
        } catch (Throwable $e) {
            $this->pixel_log->status = $e->getMessage();
            logger()->warning($e->getMessage());
            logger('Пойман Exception в pixel_log #' . $this->pixel_log->id, [$e]);
            dump($e);
        } finally {
            $this->pixel_log->save();
        }
    }

    /**
     * Проверяем, существует ли такой clientId
     * Если нет - создаем
     *
     * @return Client|false
     */
    public function parseClientId()
    {
        if (empty($this->pixel_log->data['uid'])) {
            throw new Exception('Пустой uid');
        }

        $this->client = Client::where('id', '=', $this->pixel_log->data['uid'])->first() ?? new Client();
        $this->client->id = $this->pixel_log->data['uid'];
        $this->client->pp_id = $this->pixel_log->pp_id;
        $this->client->save();

        return $this->client;
    }

    public function parseLink()
    {
        // Обрабатываем тот момент, когда url содержит в себе наши UTM-метки
        $this->link = Link::query()
            ->where('pp_id', '=', $this->pixel_log->pp_id)
            ->where('id', '=', $this->pixel_log->data['utm_campaign'])
            ->where('partner_id', '=', $this->pixel_log->data['utm_content'])
            ->first();

        if (!$this->link) {
            throw new Exception('Не найден линк #' . $this->pixel_log->data['utm_campaign'] . ' у партнера #' . $this->pixel_log->data['utm_content']);
        }
        return $this->link;
    }

    public function parseClick(): ?Click
    {
        $this->pixel_log->is_click = false;
        if (!$this->pixel_log->isClick()) {
            // Это не клик, пропускаем
            return null;
        }

        if (!$this->link) {
            throw new Exception('Не найден линк #' . $this->pixel_log->data['utm_campaign'] . ' у партнера #' . $this->pixel_log->data['utm_content']);
        }

        // Тут мы проверяем, что данная запись не существовала до этого в таблице clicks
        $click = Click::query()
                ->where('pp_id', '=', $this->pixel_log->pp_id)
                ->where('pixel_log_id', '=', $this->pixel_log->id)
                ->first() ?? new Click();
        $click->pp_id = $this->pixel_log->pp_id;
        $click->partner_id = $this->link->partner_id;
        $click->link_id = $this->link->id;
        $click->client_id = $this->client->id;
        $click->click_id = $this->pixel_log->data['click_id'] ?? null;
        $click->web_id = $this->pixel_log->data['utm_term'] ?? null;
        $click->pixel_log_id = $this->pixel_log->id;
        $click->save();

        $this->pixel_log->is_click = true;
        return $click;
    }

    public function parsePurchase()
    {
        if ($this->pixel_log->isPurchase() === false) {
            logger()->debug('Не является заказом');
            $this->pixel_log->is_order = false;
            return false;
        }

        $this->pixel_log->is_order = false;
        if ($this->pixel_log->data['ev'] === 'purchase' && !empty($this->pixel_log->data['ed']['order_id']) && !empty($this->pixel_log->data['dataLayer'])) {
            logger()->debug('Это ваще-заказ в 1 клик');
            $this->parseCheckoutDataLayerEvent();
            return true;
        } elseif ($this->pixel_log->data['ev'] === 'pageload' && !empty($this->pixel_log->data['dataLayer'])) {
            logger()->debug('Это ваще-заказ');
            $this->parseDataLayerEvent();
            return true;
        } elseif ($this->pixel_log->data['ev'] === 'purchase' && !empty($this->pixel_log->data['ed']) && !empty($this->pixel_log->data['ed']['order_id'])) {
            // pixel_event
            logger()->debug('Это лид-заказ');
            $this->parsePurchaseEvent();
            return true;
        } else {
            logger()->debug('Это странный заказ');
            dump($this->pixel_log->data);
            throw new Exception('Странный формат заказа!');
        }
    }

    public function parsePurchaseEvent()
    {
        $order_id = $this->pixel_log->data['ed']['order_id'];
        $order = Order::query()
                ->where('pp_id', '=', $this->pixel_log->pp_id)
                ->where('order_id', '=', $order_id)
                ->first() ?? new Order();

        $order->order_id = $order_id;
        $order->datetime = $this->pixel_log->created_at;
        $order->pp_id = $this->pixel_log->pp_id;
        $order->partner_id = $this->link->partner_id;
        $order->link_id = $this->link->id;
        $order->click_id = $this->pixel_log->getClickId() ?? null;
        $order->web_id = $this->pixel_log->data['utm_term'] ?? null;
        $order->offer_id = $this->link->offer_id;
        $order->client_id = $this->client->id;
        $order->pixel_id = $this->pixel_log->id;
        $order->status = 'new';
        $order->save();

        $this->orderComplete();

        logger()->debug('Это продажа');
    }


    /**
     * Обработка события заказа
     * @param ProductService $productService
     * @return bool
     */
    public function parseDataLayerEvent(ProductService $productService): bool
    {
        // свои переменные называю в кемл кейс, за исключение полей элоквент в команде следую принятому стандарту
        // рефакторинг достаточно поверхностный, так как без контекста
        foreach ($this->pixel_log->getEvents() as $event) {
            if (!isset($event['event']) && !empty($event['ecommerce']['purchase'])) {
                continue; // возможно тут должно быть исключение
            }
            /** @var Purchase $purchase */
            $purchase = $event['ecommerce']['purchase'];
            $purchase->validate();
            $orderService = new OrderService($this->pixel_log, $this->link, $this->client);
            $order = $orderService->createOrUpdateOrder($purchase);

            foreach ($purchase->getProducts() as $product_data) {
                $productService->productOrdered($order, $product_data, $this->pixel_log->pp_id);
            }
        }
        return $this->orderComplete();  // не уверен что угадал с названием метода
    }

    /**
     *
     */
    public function parseCheckoutDataLayerEvent()
    {
        // будет содержать больше комментариев, чем обычно, чтоб проще было читать тестовое задание
        // лично я именую всё в кэмл кейс, кроме полей моделей в ларавель, но не буду с этим заморачиваться тут
        $events = $this->pixel_log->getEvents(); // всегда возвращает массив, что упраздняет проверку на массив

        foreach ($events as $event) {
            if (!isset($event['event'])) {
                continue;
            }
            if (!isset($event['ecommerce'])) {
                continue;
            }
            if (!isset($event['ecommerce']['checkout'])) {
                continue;
            }

            $purchase = $event['ecommerce']['checkout'];
            $validator = Validator::make($purchase, [
                'products.*.id' => 'required|string',
                'products.*.name' => 'required|string',
                'products.*.price' => 'required|numeric',
                'products.*.variant' => 'nullable|string',
                'products.*.category' => 'nullable|string',
                'products.*.quantity' => 'nullable|numeric|min:1',
            ]);

            if ($validator->fails()) {
                logger()->debug('Ошибка валидации заказа');
                throw new ValidationException($validator);
            }

            $order_id = $this->pixel_log->data['ed']['order_id'];
            $order = $this->getOrder($order_id);

            if (!$order) {
                $order = $this->createNewOrder($order_id);
            } else {
                logger()->debug('Заказ №' . $order_id . ' существует, обновляем');
            }
            $product_data = $this->updateOrder($order, $purchase['products']);

            logger()->debug('Найдено продуктов: ' . count($purchase['products']));
            foreach ($purchase['products'] as $product_data) {
                $product_id = $product_data['id'];
                $product = OrdersProduct::query()
                        ->where('pp_id', '=', $this->pixel_log->pp_id)
                        ->where('order_id', '=', $order->order_id)
                        ->where('product_id', '=', $product_id)
                        ->first() ?? new OrdersProduct();

                $product->pp_id = $this->pixel_log->pp_id;
                $product->order_id = $order->order_id;
                $product->datetime = $order->datetime;
                $product->partner_id = $order->partner_id;
                $product->offer_id = $order->offer_id;
                $product->link_id = $order->link_id;
                $product->product_id = $product_id;
                $product->product_name = trim(($product_data['name'] ?? '') . ' ' . ($product_data['variant'] ?? ''));
                $product->category = $product_data['category'] ?? null;
                $product->price = $product_data['price'];
                $product->quantity = $product_data['quantity'] ?? 1;
                $product->total = $product->price * $product->quantity;
                $product->web_id = $order->web_id;
                $product->click_id = $order->click_id;
                $product->pixel_id = $order->pixel_id;
                $product->amount = 0;
                $product->amount_advert = 0;
                $product->fee_advert = 0;
                $product->save();
                logger()->debug('Сохранен продукт: ' . $product->product_name);
            }
            $this->orderComplete();
            return true;
        }
    }

    /**
     * @return bool
     */
    private function orderComplete(): bool
    {
        $this->pixel_log->is_order = true;
        return true;
    }

}