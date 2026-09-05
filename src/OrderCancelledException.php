<?php

declare(strict_types=1);

namespace App;

/**
 * Thrown when a mutation is attempted on a cancelled (terminal) order.
 *
 * Code review fix, spec 020: cancellation is meant to be a stable,
 * audit-preserved terminal state, but only completeOrder()/uncompleteOrder()/
 * cancelOrder() actually enforced that — updateOrder()/addOrderItem()/
 * updateOrderItem()/removeOrderItem() silently allowed mutating a cancelled
 * order's fields/items. A distinct type (not a plain \DomainException) so
 * OrderController can tell it apart from this project's other,
 * differently-coded \DomainException cases already caught in the same
 * methods (e.g. "menu item unavailable", "can't remove the last item").
 */
final class OrderCancelledException extends \DomainException
{
}
