<?php

namespace Magento\Vault\Api\Data;


interface PaymentTokenInterface
{

    function setCustomerId(int $id);

    function getCustomerId(): int;

    function setGatewayToken(string $val);

    function getGatewayToken(): string;

    function setPaymentMethodCode(string $val);

    function getPaymentMethodCode(): string;

    function setIsVisible(bool $val);

    function getIsVisible(): bool;

    function setWebsiteId(int $val);

    function getWebsiteId(): int;

    function setTokenDetails(string $val);

    function getTokenDetails(): string;

    function setExpiresAt(\DateTime $val);

    function getExpiresAt(): \DateTime;

    function setPublicHash(string $val);

    function getPublicHash(): string;


    function getId();

    function getEntityId();

    function getIsActive();

}