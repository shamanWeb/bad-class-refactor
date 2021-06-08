<?php


namespace App\Models;


class PixelLog
{

    /**
     * @var array
     */
    private $data;

    /**
     * @param array $data
     */
    public function setData(array $data): void
    {
        // странно что тут массив дата, хотя неймспейс указывает на модель элоквент
        // возможно модель используется неверно
        $this->data = $data;
    }

    /**
     * @return mixed
     */
    public function getEvents(): ?array
    {
        return $this->data['dataLayer'];
    }

    public function getClickId(): ?array
    {
        return $this->data['click_id'];
    }

    public function getUtmTerm(): ?array
    {
        return $this->data['utm_term'];
    }
}