<?php


class Purchase
{
    /**
     * Данные заказа
     * @var array
     */
    private $purchaseData;

    /**
     * Purchase constructor.
     * @param array $purchaseData откуда то там приходит или класс уже содержится в $event, оставим так
     */
    public function __construct(array $purchaseData)
    {
        $this->purchaseData = $purchaseData;
    }

    public function getOrderId(): int
    {
        return $this->purchaseData['actionField']['id'];
    }

    public function getProducts(): array
    {
        return $this->purchaseData['products'];
    }

    public function getProductsCount(): int
    {
        return count($this->purchaseData['products']);
    }

    /**
     * @throws ValidationException
     */
    public function validate(): void // возможно было бы лучше выполнять в конструкторе
    {
        $validator = Validator::make($this->purchaseData, [
            'products.*.id' => 'required|string',
            'products.*.name' => 'required|string',
            'products.*.price' => 'required|numeric',
            'products.*.variant' => 'nullable|string',
            'products.*.category' => 'nullable|string',
            'products.*.quantity' => 'nullable|numeric|min:1',
            'actionField.id' => 'required|string',
            'actionField.action' => 'nullable|string|in:purchase',
            'actionField.revenue' => 'required|numeric',
        ]);

        if ($validator->fails()) {
            logger()->debug('Ошибка валидации заказа');
            throw new ValidationException($validator);
        }
    }
}