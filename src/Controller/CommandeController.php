<?php
namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Doctrine\DBAL\Connection;

final class CommandeController extends AbstractController
{
    #[Route('/commandes', name: 'app_commandes')]
    public function index(Connection $connection): Response
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