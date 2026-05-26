<?php
// ============================================================
// VESTIA API — Order Controller
// ============================================================
class OrderController {

    public static function index(): void {
        $user   = getAuthUser();
        $db     = getDB();
        $status = $_GET['status'] ?? null;

        $where  = ['o.user_id = ?'];
        $params = [$user['id']];

        if ($status === 'ongoing') {
            $where[] = "o.status IN ('Packing','Picked','In Transit')";
        } elseif ($status === 'completed') {
            $where[] = "o.status = 'Completed'";
        }

        $whereSQL = implode(' AND ', $where);

        $stmt = $db->prepare(
            "SELECT o.id, o.status, o.subtotal, o.shipping_fee, o.vat, o.total, o.created_at
             FROM orders o
             WHERE {$whereSQL}
             ORDER BY o.created_at DESC"
        );
        $stmt->execute($params);
        $orders = $stmt->fetchAll();

        foreach ($orders as &$order) {
            // ✅ يجلب الأسماء بالثلاث لغات
            $items = $db->prepare(
                'SELECT oi.id, oi.name, oi.name_ar, oi.name_fr,
                        oi.image_url, oi.price, oi.quantity, oi.size, oi.product_id
                 FROM order_items oi WHERE oi.order_id = ?'
            );
            $items->execute([$order['id']]);
            $order['items'] = $items->fetchAll();

            foreach ($order['items'] as &$item) {
                $item['image_url'] = fixImageUrl($item['image_url']);

                // ✅ fallback: إذا كان name_ar أو name_fr فارغاً يرجع الاسم الإنجليزي
                $item['name_ar'] = $item['name_ar'] ?: $item['name'];
                $item['name_fr'] = $item['name_fr'] ?: $item['name'];

                // ✅ التحقق من التقييم مرتبط بالطلبية تحديداً
                try {
                    $rev = $db->prepare(
                        'SELECT id, rating FROM reviews
                         WHERE user_id = ? AND product_id = ? AND order_id = ?'
                    );
                    $rev->execute([$user['id'], $item['product_id'], $order['id']]);
                } catch (\Exception $e) {
                    // fallback: إذا لم يكن عمود order_id موجوداً بعد
                    $rev = $db->prepare(
                        'SELECT id, rating FROM reviews
                         WHERE user_id = ? AND product_id = ?'
                    );
                    $rev->execute([$user['id'], $item['product_id']]);
                }

                $review = $rev->fetch();
                $item['reviewed'] = (bool)$review;
                $item['rating']   = $review ? (float)$review['rating'] : null;
            }
        }

        jsonSuccess(['orders' => $orders]);
    }

    public static function show(string $id): void {
        $user = getAuthUser();
        $db   = getDB();

        $stmt = $db->prepare('SELECT * FROM orders WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $user['id']]);
        $order = $stmt->fetch();

        if (!$order) jsonError('Order not found', 404);

        // ✅ يجلب الأسماء بالثلاث لغات
        $items = $db->prepare(
            'SELECT oi.id, oi.name, oi.name_ar, oi.name_fr,
                    oi.image_url, oi.price, oi.quantity, oi.size, oi.product_id
             FROM order_items oi WHERE oi.order_id = ?'
        );
        $items->execute([$id]);
        $rawItems = $items->fetchAll();

        foreach ($rawItems as &$item) {
            $item['image_url'] = fixImageUrl($item['image_url']);
            $item['name_ar']   = $item['name_ar'] ?: $item['name'];
            $item['name_fr']   = $item['name_fr'] ?: $item['name'];
        }
        $order['items'] = $rawItems;

        jsonSuccess(['order' => $order]);
    }

    public static function store(): void {
        $user = getAuthUser();
        $db   = getDB();

        // ✅ يجلب الأسماء بالثلاث لغات من جدول products
        $stmt = $db->prepare(
            "SELECT c.quantity, c.size, p.id AS product_id,
                    p.name, p.name_ar, p.name_fr,
                    p.price, p.image_url
             FROM cart_items c
             JOIN products p ON p.id = c.product_id
             WHERE c.user_id = ? AND p.is_active = 1"
        );
        $stmt->execute([$user['id']]);
        $cartItems = $stmt->fetchAll();

        if (empty($cartItems)) jsonError('Cart is empty', 422);

        $subtotal    = array_sum(array_map(fn($i) => $i['price'] * $i['quantity'], $cartItems));
        $shippingFee = SHIPPING_FEE;
        $total       = $subtotal + $shippingFee;

        $db->beginTransaction();
        try {
            $insertStmt = $db->prepare(
                'INSERT INTO orders (user_id, status, subtotal, shipping_fee, vat, total) VALUES (?,?,?,?,?,?) RETURNING id'
            );
            $insertStmt->execute([$user['id'], 'Packing', $subtotal, $shippingFee, 0, $total]);
            $orderId = (int)$insertStmt->fetchColumn();

            // ✅ يحفظ الأسماء بالثلاث لغات في order_items
            $insertItem = $db->prepare(
                'INSERT INTO order_items
                 (order_id, product_id, name, name_ar, name_fr, image_url, price, quantity, size)
                 VALUES (?,?,?,?,?,?,?,?,?)'
            );
            foreach ($cartItems as $item) {
                $insertItem->execute([
                    $orderId,
                    $item['product_id'],
                    $item['name'],
                    $item['name_ar'] ?? $item['name'],
                    $item['name_fr'] ?? $item['name'],
                    $item['image_url'],
                    $item['price'],
                    $item['quantity'],
                    $item['size']
                ]);
            }

            $db->prepare('DELETE FROM cart_items WHERE user_id = ?')->execute([$user['id']]);

            $db->commit();
            jsonSuccess(['order_id' => $orderId], 'Order placed successfully', 201);

        } catch (Exception $e) {
            $db->rollBack();
           jsonError($e->getMessage(), 500); // ← مؤقت
        }
    }
}
