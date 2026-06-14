<?php
// ============================================================
// VESTIA API — Cart Controller
// ============================================================
class CartController {

    private static function getCartData($userId): array {
        $db = getDB();
        
        $stmt = $db->prepare(
            "SELECT c.id, c.quantity, c.size,
                    p.id AS product_id, p.name, p.name_ar, p.name_fr,
                    p.price, p.old_price, p.image_url
             FROM cart_items c
             JOIN products p ON p.id = c.product_id
             WHERE c.user_id = ? AND p.is_active = 1
             ORDER BY c.created_at DESC"
        );
        $stmt->execute([$userId]);
        $items = $stmt->fetchAll();

        $items = array_map(function($item) {
            $item['image_url'] = fixImageUrl($item['image_url']);
            $item['name_ar'] = $item['name_ar'] ?: $item['name'];
            $item['name_fr'] = $item['name_fr'] ?: $item['name'];
            return $item;
        }, $items);

        $subtotal    = array_sum(array_map(fn($i) => $i['price'] * $i['quantity'], $items));
        $shippingFee = count($items) > 0 ? SHIPPING_FEE : 0;

        return [
            'items'        => $items,
            'subtotal'     => round($subtotal, 2),
            'shipping_fee' => $shippingFee,
            'vat'          => 0,
            'total'        => round($subtotal + $shippingFee, 2),
            'item_count'   => array_sum(array_column($items, 'quantity')),
        ];
    }

    public static function index(): void {
        $user = getAuthUser();
        jsonSuccess(self::getCartData($user['id']));
    }

    public static function add(): void {
        $user = getAuthUser();
        $body = getRequestBody();

        $productId = (int)($body['product_id'] ?? 0);
        // ✅ تحديد الحد الأقصى للكمية منعاً للتلاعب
        $quantity  = min(99, max(1, (int)($body['quantity'] ?? 1)));
        $size      = strtoupper(sanitize($body['size'] ?? 'M'));

        // ✅ التحقق من أن الـ size صحيح
        $allowedSizes = ['XS', 'S', 'M', 'L', 'XL', 'XXL'];
        if (!in_array($size, $allowedSizes)) jsonError('Invalid size', 422);

        if (!$productId) jsonError('product_id is required', 422);

        $db = getDB();

        $check = $db->prepare('SELECT id FROM products WHERE id = ? AND is_active = 1');
        $check->execute([$productId]);
        if (!$check->fetch()) jsonError('Product not found', 404);

        $db->prepare(
            "INSERT INTO cart_items (user_id, product_id, quantity, size)
             VALUES (?, ?, ?, ?)
             ON CONFLICT (user_id, product_id, size)
             DO UPDATE SET quantity = cart_items.quantity + EXCLUDED.quantity"
        )->execute([$user['id'], $productId, $quantity, $size]);

        $cartData = self::getCartData($user['id']);
        jsonSuccess($cartData, 'Added to cart', 201);
    }

    public static function update(?string $id): void {
        $user = getAuthUser();
        if (!$id || !ctype_digit($id)) jsonError('Cart item ID required', 422);

        $body     = getRequestBody();
        // ✅ تحديد الحد الأقصى للكمية منعاً للتلاعب
        $quantity = min(99, (int)($body['quantity'] ?? 0));
        $db       = getDB();

        if ($quantity <= 0) {
            $db->prepare('DELETE FROM cart_items WHERE id = ? AND user_id = ?')
               ->execute([$id, $user['id']]);
        } else {
            $db->prepare('UPDATE cart_items SET quantity = ? WHERE id = ? AND user_id = ?')
               ->execute([$quantity, $id, $user['id']]);
        }

        $cartData = self::getCartData($user['id']);
        jsonSuccess($cartData, $quantity <= 0 ? 'Item removed' : 'Cart updated');
    }

    public static function remove(?string $id): void {
        $user = getAuthUser();
        if (!$id || !ctype_digit($id)) jsonError('Cart item ID required', 422);

        $db = getDB();
        $db->prepare('DELETE FROM cart_items WHERE id = ? AND user_id = ?')
           ->execute([$id, $user['id']]);

        $cartData = self::getCartData($user['id']);
        jsonSuccess($cartData, 'Item removed from cart');
    }
}
