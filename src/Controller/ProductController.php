<?php
namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Doctrine\DBAL\Connection;

final class ProductController extends AbstractController
{
    #[Route('/products', name: 'app_products')]
    public function index(Request $request, Connection $connection): Response
    {
        $categoryId = $request->query->get('category');
        $sortPrice  = $request->query->get('sort_price');

        $order = match(strtolower((string) $sortPrice)) {
            'asc'  => 'ps.price ASC',
            'desc' => 'ps.price DESC',
            default => 'pl.name ASC',
        };

        $sql = "
            SELECT 
                p.id_product,
                pl.name,
                p.reference,
                ROUND(ps.price * 1.2, 2) as price,
                sa.quantity,
                CASE WHEN sa.quantity > 0 THEN 'Oui' ELSE 'Non' END as in_stock,
                (
                    SELECT cl2.name 
                    FROM ps_category_product cp2
                    LEFT JOIN ps_category_lang cl2 ON cp2.id_category = cl2.id_category AND cl2.id_lang = 1
                    WHERE cp2.id_product = p.id_product
                    LIMIT 1
                ) as category_name
            FROM ps_product p
            LEFT JOIN ps_product_lang pl ON p.id_product = pl.id_product AND pl.id_lang = 1
            LEFT JOIN ps_product_shop ps ON p.id_product = ps.id_product AND ps.id_shop = 1
            LEFT JOIN ps_stock_available sa ON p.id_product = sa.id_product AND sa.id_product_attribute = 0
            WHERE (:category_id IS NULL OR p.id_product IN (
                SELECT id_product FROM ps_category_product WHERE id_category = :category_id
            ))
            ORDER BY $order
        ";

        $products = $connection->fetchAllAssociative($sql, [
            'category_id' => $categoryId ? (int) $categoryId : null,
        ]);

        $categoriesSql = "
            SELECT DISTINCT cp.id_category, cl.name
            FROM ps_category_product cp
            LEFT JOIN ps_category_lang cl ON cp.id_category = cl.id_category AND cl.id_lang = 1
            WHERE cl.name IS NOT NULL
            ORDER BY cl.name ASC
        ";
        $categories = $connection->fetchAllAssociative($categoriesSql);

        return $this->render('product/index.html.twig', [
            'products'         => $products,
            'categories'       => $categories,
            'current_category' => $categoryId,
            'current_sort'     => $sortPrice,
        ]);
    }

    #[Route('/products/new', name: 'app_product_new', methods: ['GET', 'POST'])]
    public function new(Request $request, Connection $connection): Response
    {
        $categoriesSql = "
            SELECT DISTINCT cp.id_category, cl.name
            FROM ps_category_product cp
            LEFT JOIN ps_category_lang cl ON cp.id_category = cl.id_category AND cl.id_lang = 1
            WHERE cl.name IS NOT NULL
            ORDER BY cl.name ASC
        ";
        $categories = $connection->fetchAllAssociative($categoriesSql);

        if ($request->isMethod('POST')) {
            $name       = $request->request->get('name');
            $reference  = $request->request->get('reference');
            $price      = (float) $request->request->get('price') / 1.2;
            $quantity   = (int) $request->request->get('quantity');
            $width      = (float) $request->request->get('width');
            $height     = (float) $request->request->get('height');
            $depth      = (float) $request->request->get('depth');
            $weight     = (float) $request->request->get('weight');
            $categoryId = (int) $request->request->get('category');

            $connection->executeStatement("
                INSERT INTO ps_product (id_shop_default, id_manufacturer, id_supplier, reference, width, height, depth, weight, date_add, date_upd)
                VALUES (1, 0, 0, :reference, :width, :height, :depth, :weight, NOW(), NOW())
            ", compact('reference', 'width', 'height', 'depth', 'weight'));

            $productId = (int) $connection->lastInsertId();

            $connection->executeStatement("
                INSERT INTO ps_product_lang (id_product, id_lang, id_shop, name, link_rewrite)
                VALUES (:id, 1, 1, :name, :slug)
            ", [
                'id'   => $productId,
                'name' => $name,
                'slug' => strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name)),
            ]);

            $connection->executeStatement("
                INSERT INTO ps_product_shop (id_product, id_shop, price, date_add, date_upd)
                VALUES (:id, 1, :price, NOW(), NOW())
            ", ['id' => $productId, 'price' => $price]);

            $connection->executeStatement("
                INSERT INTO ps_stock_available (id_product, id_product_attribute, id_shop, id_shop_group, quantity)
                VALUES (:id, 0, 1, 1, :quantity)
            ", ['id' => $productId, 'quantity' => $quantity]);

            if ($categoryId) {
                $connection->executeStatement("
                    INSERT INTO ps_category_product (id_category, id_product, position)
                    VALUES (:category_id, :id, 0)
                ", ['category_id' => $categoryId, 'id' => $productId]);
            }

            return $this->redirectToRoute('app_products');
        }

        return $this->render('product/new.html.twig', [
            'categories' => $categories,
        ]);
    }
    #[Route('/products/{id}/edit', name: 'app_product_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
public function edit(int $id, Request $request, Connection $connection): Response
{
    $sql = "
        SELECT 
            p.id_product,
            pl.name,
            p.reference,
            ROUND(ps.price * 1.2, 2) as price,
            sa.quantity,
            ROUND(p.width, 2) as width,
            ROUND(p.height, 2) as height,
            ROUND(p.depth, 2) as depth,
            ROUND(p.weight, 2) as weight,
            (SELECT id_category FROM ps_category_product WHERE id_product = p.id_product LIMIT 1) as id_category
        FROM ps_product p
        LEFT JOIN ps_product_lang pl ON p.id_product = pl.id_product AND pl.id_lang = 1
        LEFT JOIN ps_product_shop ps ON p.id_product = ps.id_product AND ps.id_shop = 1
        LEFT JOIN ps_stock_available sa ON p.id_product = sa.id_product AND sa.id_product_attribute = 0
        WHERE p.id_product = :id
        GROUP BY p.id_product, pl.name, p.reference, ps.price, sa.quantity, p.width, p.height, p.depth, p.weight
    ";
    $product = $connection->fetchAssociative($sql, ['id' => $id]);

    if (!$product) {
        throw $this->createNotFoundException('Produit introuvable');
    }

    $categoriesSql = "
        SELECT DISTINCT cp.id_category, cl.name
        FROM ps_category_product cp
        LEFT JOIN ps_category_lang cl ON cp.id_category = cl.id_category AND cl.id_lang = 1
        WHERE cl.name IS NOT NULL
        ORDER BY cl.name ASC
    ";
    $categories = $connection->fetchAllAssociative($categoriesSql);

    if ($request->isMethod('POST')) {
        $name       = $request->request->get('name');
        $reference  = $request->request->get('reference');
        $price      = (float) $request->request->get('price') / 1.2;
        $quantity   = (int) $request->request->get('quantity');
        $width      = (float) $request->request->get('width');
        $height     = (float) $request->request->get('height');
        $depth      = (float) $request->request->get('depth');
        $weight     = (float) $request->request->get('weight');
        $categoryId = (int) $request->request->get('category');

        $connection->executeStatement("
            UPDATE ps_product 
            SET reference = :reference, width = :width, height = :height, depth = :depth, weight = :weight, date_upd = NOW()
            WHERE id_product = :id
        ", compact('reference', 'width', 'height', 'depth', 'weight', 'id'));

        $connection->executeStatement("
            UPDATE ps_product_lang 
            SET name = :name
            WHERE id_product = :id AND id_lang = 1
        ", ['name' => $name, 'id' => $id]);

        $connection->executeStatement("
            UPDATE ps_product_shop 
            SET price = :price, date_upd = NOW()
            WHERE id_product = :id AND id_shop = 1
        ", ['price' => $price, 'id' => $id]);

        $connection->executeStatement("
            UPDATE ps_stock_available 
            SET quantity = :quantity
            WHERE id_product = :id AND id_product_attribute = 0
        ", ['quantity' => $quantity, 'id' => $id]);

        // Mise à jour catégorie
        $connection->executeStatement("
            DELETE FROM ps_category_product WHERE id_product = :id
        ", ['id' => $id]);

        if ($categoryId) {
            $connection->executeStatement("
                INSERT INTO ps_category_product (id_category, id_product, position)
                VALUES (:category_id, :id, 0)
            ", ['category_id' => $categoryId, 'id' => $id]);
        }

        return $this->redirectToRoute('app_products');
    }

    return $this->render('product/edit.html.twig', [
        'product'    => $product,
        'categories' => $categories,
    ]);
}
    #[Route('/products/{id}/delete', name: 'app_product_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(int $id, Connection $connection, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('delete' . $id, $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF invalide.');
        }

        $connection->executeStatement('DELETE FROM ps_product_lang WHERE id_product = :id', ['id' => $id]);
        $connection->executeStatement('DELETE FROM ps_product_shop WHERE id_product = :id', ['id' => $id]);
        $connection->executeStatement('DELETE FROM ps_stock_available WHERE id_product = :id', ['id' => $id]);
        $connection->executeStatement('DELETE FROM ps_category_product WHERE id_product = :id', ['id' => $id]);
        $connection->executeStatement('DELETE FROM ps_product WHERE id_product = :id', ['id' => $id]);

        return $this->redirectToRoute('app_products');
    }

    #[Route('/products/{id}', name: 'app_product_show', requirements: ['id' => '\d+'])]
    public function show(int $id, Connection $connection): Response
    {
        $sql = "
            SELECT 
                p.id_product,
                pl.name,
                p.reference,
                ROUND(ps.price * 1.2, 2) as price,
                sa.quantity,
                CASE WHEN sa.quantity > 0 THEN 'Oui' ELSE 'Non' END as in_stock,
                ROUND(p.width, 2) as width,
                ROUND(p.height, 2) as height,
                ROUND(p.depth, 2) as depth,
                ROUND(p.weight, 2) as weight
            FROM ps_product p
            LEFT JOIN ps_product_lang pl ON p.id_product = pl.id_product AND pl.id_lang = 1
            LEFT JOIN ps_product_shop ps ON p.id_product = ps.id_product AND ps.id_shop = 1
            LEFT JOIN ps_stock_available sa ON p.id_product = sa.id_product AND sa.id_product_attribute = 0
            WHERE p.id_product = :id
            GROUP BY p.id_product, pl.name, p.reference, ps.price, sa.quantity, p.width, p.height, p.depth, p.weight
        ";

        $product = $connection->fetchAssociative($sql, ['id' => $id]);

        if (!$product) {
            throw $this->createNotFoundException('Produit introuvable');
        }

        $history = [];
            $historySql = "
                SELECT 
                    DATE_FORMAT(o.date_add, '%Y-%m') as month,
                    SUM(od.product_quantity) as exits
                FROM ps_order_detail od
                LEFT JOIN ps_orders o ON od.id_order = o.id_order
                WHERE od.product_id = :id
                GROUP BY DATE_FORMAT(o.date_add, '%Y-%m')
                ORDER BY month ASC
            ";
            $history = $connection->fetchAllAssociative($historySql, ['id' => $id]);

        return $this->render('product/show.html.twig', [
            'product' => $product,
            'history' => $history,
        ]);
    }

    #[Route('/commandes', name: 'app_commandes')]
    public function commandes(Connection $connection): Response
    {
        $sql = "
            SELECT 
                p.id_product,
                pl.name,
                p.reference,
                ROUND(ps.price * 1.2, 2) as price,
                sa.quantity,
                (
                    SELECT cl2.name 
                    FROM ps_category_product cp2
                    LEFT JOIN ps_category_lang cl2 ON cp2.id_category = cl2.id_category AND cl2.id_lang = 1
                    WHERE cp2.id_product = p.id_product
                    LIMIT 1
                ) as category_name
            FROM ps_product p
            LEFT JOIN ps_product_lang pl ON p.id_product = pl.id_product AND pl.id_lang = 1
            LEFT JOIN ps_product_shop ps ON p.id_product = ps.id_product AND ps.id_shop = 1
            LEFT JOIN ps_stock_available sa ON p.id_product = sa.id_product AND sa.id_product_attribute = 0
            WHERE sa.quantity <= 10
            ORDER BY sa.quantity ASC
        ";

        $products = $connection->fetchAllAssociative($sql);

        return $this->render('product/commandes.html.twig', [
            'products' => $products,
        ]);
    }
  
}
