<?php

namespace App\Controller;

use App\Entity\Order;
use App\Repository\OrderRepository;
use Stripe\StripeClient;
use App\Service\CartService;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

class PaymentController extends AbstractController
{
    #[Route('/payment/{order}', name: 'payment')]
    public function index(Request $request, CartService $cartService, Order $order): Response
    {
        if ($request->headers->get('referer') !== 'https://127.0.0.1:8000/cart/validation') {
            return $this->redirectToRoute('cart');
        }

        $sessionCart = $cartService->getCart();
        $stripeCart = [];

        foreach ($sessionCart as $line) {
            $stripeElement = [ // produit tel que Stripe en a besoin pour le traiter, les noms des index sont importants
                'quantity' => $line['quantity'],
                'price_data' => [
                    'currency' => 'EUR',
                    'unit_amount' => $line['product']->getPrice() * 100,
                    'product_data' => [
                        'name' => $line['product']->getName(),
                        // 'description' => $line['product']->getDescription(),
                        // 'images' => [// 'https://127.0.0.1:8000/public/img/product/' . $line['product']->getImg1()]
                    ]
                ]
            ];
            $stripeCart[] = $stripeElement;
        }

        $carrier = $order->getCarrier();
        $stripeElement = [
            'quantity' => 1,
            'price_data' => [
                'currency' => 'EUR',
                'unit_amount' => $carrier->getPrice() * 100,
                'product_data' => [
                    'name' => 'Livraison : ' . $carrier->getName(),
                    'images' => [
                        'https://127.0.0.1:8000/public/img/carrier/' . $carrier->getImg()
                    ]
                ]
            ]
        ];

        $stripeCart[] = $stripeElement;

        $stripe = new StripeClient($this->getParameter('stripe_secret_key'));

        $stripeSession = $stripe->checkout->sessions->create([
            'line_items' => $stripeCart,
            'mode' => 'payment',
            'success_url' => 'https://127.0.0.1:8000/payment/' . $order->getId() . '/success',
            'cancel_url' => 'https://127.0.0.1:8000/payment/cancel',
            'payment_method_types' => ['card']
        ]);


        return $this->render('payment/index.html.twig', [
            'sessionId' => $stripeSession->id,
            'order' => $order->getId()
        ]);
    }

    #[Route('payment/{order}/success', name: 'payment_success')]
    public function success(Request $request, CartService $cartService, Order $order, ManagerRegistry $managerRegistry): Response
    {
        if ($request->headers->get('referer') !== 'https://checkout.stripe.com/') {
            return $this->redirectToRoute('cart');
        }

        $cartService->clear();
        $order->setPaid(true);
        $managerRegistry->getManager()->persist($order);
        $managerRegistry->getManager();

        foreach ($order->getOrderDetails() as $orderDetail) { // gestion des stocks restants en base de donn??es
            $product = $orderDetail->getProductId();
            $product->setQuantity($product->getQuantity() - $orderDetail->getQuantity());
            $managerRegistry->getManager()->persist($product);
        }

        $managerRegistry->getManager()->flush();


        return $this->render('payment/success.html.twig');
    }


    #[Route('payment/cancel', name: 'payment_cancel')]
    public function cancel(Request $request): Response
    {
        if ($request->headers->get('referer') !== 'https://checkout.stripe.com/') {
            return $this->redirectToRoute('cart');
        }
        return $this->render('payment/cancel.html.twig');
    }
}
