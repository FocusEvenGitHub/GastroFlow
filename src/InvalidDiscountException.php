<?php

declare(strict_types=1);

namespace App;

/**
 * A discount that breaks one of spec 058's business rules (above the item's
 * value, above the admin's maximum, discounts disabled...). Mapped to
 * 400 INVALID_DISCOUNT by OrderController — distinct from a malformed
 * request (VALIDATION_FAILED), which never reaches pricing. The message is
 * user-facing Portuguese, shown as-is by the cashier/kitchen toasts.
 */
class InvalidDiscountException extends \DomainException
{
}
