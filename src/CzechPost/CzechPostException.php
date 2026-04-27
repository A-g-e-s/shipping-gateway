<?php

namespace Ages\ShippingGateway\CzechPost;

class CzechPostException extends \Exception
{
    private mixed $extra = [];

    public function __construct(string $message, int $code = 500, mixed $extra = null)
    {
        if (is_string($extra)) {
            $extraTemp = @json_decode($extra, true);
            if (!empty($extraTemp)) {
                assert(is_array($extraTemp));
                if (count($extraTemp) == 1) {
                    $extraTemp = current($extraTemp);
                }
                $extra = $extraTemp;
            }
        }
        $this->extra = $extra;
        parent::__construct($message . '~' . @json_encode($extra, JSON_PRETTY_PRINT), $code);
    }

    /**
     * @return array<mixed>
     */
    public function getExtra(): array
    {
        return (array)$this->extra;
    }

    public function __toString(): string
    {
        return $this->getMessage() . ' [' . $this->getCode() . ']: ' . var_export($this->getExtra(), true);
    }
}
