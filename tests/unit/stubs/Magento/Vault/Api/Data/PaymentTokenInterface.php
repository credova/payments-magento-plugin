<?php

namespace Magento\Vault\Api\Data;

// Same methods as Magento's interface, which declares no parameter or return types.
interface PaymentTokenInterface
{
    function getId();
    function setCustomerId($customerId);
    function getCustomerId();
    function setGatewayToken($token);
    function getGatewayToken();
    function setPaymentMethodCode($code);
    function getPaymentMethodCode();
    function setType($type);
    function getType();
    function setIsActive($isActive);
    function getIsActive();
    function setIsVisible($isVisible);
    function getIsVisible();
    function setWebsiteId($websiteId);
    function getWebsiteId();
    function setTokenDetails($details);
    function getTokenDetails();
    function setExpiresAt($timestamp);
    function getExpiresAt();
    function setPublicHash($hash);
    function getPublicHash();
}
