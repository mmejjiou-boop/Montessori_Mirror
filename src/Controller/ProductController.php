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
}