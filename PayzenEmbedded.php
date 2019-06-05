<?php
/*************************************************************************************/
/*      This file is part of the Thelia package.                                     */
/*                                                                                   */
/*      Copyright (c) OpenStudio                                                     */
/*      email : dev@thelia.net                                                       */
/*      web : http://www.thelia.net                                                  */
/*                                                                                   */
/*      For the full copyright and license information, please view the LICENSE.txt  */
/*      file that was distributed with this source code.                             */
/*************************************************************************************/

namespace PayzenEmbedded;

use PayzenEmbedded\LyraClient\LyraJavascriptClientManagementWrapper;
use PayzenEmbedded\Model\PayzenEmbeddedCustomerTokenQuery;
use Propel\Runtime\Connection\ConnectionInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Thelia\Core\HttpFoundation\Response;
use Thelia\Core\Template\ParserInterface;
use Thelia\Core\Translation\Translator;
use Thelia\Install\Database;
use Thelia\Model\Lang;
use Thelia\Model\LangQuery;
use Thelia\Model\Message;
use Thelia\Model\MessageQuery;
use Thelia\Model\ModuleImageQuery;
use Thelia\Model\Order;
use Thelia\Module\AbstractPaymentModule;
use Thelia\Tools\URL;

class PayzenEmbedded extends AbstractPaymentModule
{
    /** @var string */
    const DOMAIN_NAME = 'payzenembedded';
    /**
     * The confirmation message identifier
     */
    const CONFIRMATION_MESSAGE_NAME = 'payzen_embedded_payment_confirmation';

    /**
     * @param Order $order
     *
     * @return \Symfony\Component\HttpFoundation\Response
     * @throws \Lyra\Exceptions\LyraException
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public function pay(Order $order)
    {
        return $this->processJavascriptClientPayment($order);
    }

    /**
     * Process a payment using the PayZen javascript client
     *
     * @param Order $order
     *
     * @return \Thelia\Core\HttpFoundation\Response
     * @throws \Lyra\Exceptions\LyraException
     * @throws \Propel\Runtime\Exception\PropelException
     */
    protected function processJavascriptClientPayment(Order $order)
    {
        // Use the embedded javascript client
        $lyraClient = new LyraJavascriptClientManagementWrapper($this->getDispatcher());

        $resultData = $lyraClient->payOrder($order);

        /** @var ParserInterface $parser */
        $parser = $this->getContainer()->get("thelia.parser");

        $parser->setTemplateDefinition(
            $parser->getTemplateHelper()->getActiveFrontTemplate()
        );

        // Display the payement page which includes teh javascript form.
        $renderedTemplate = $parser->render(
            "payzen-embedded/payment-page.html",
            array_merge(
                [
                    "order_id"   => $order->getId(),
                    "cart_count" => $this->getRequest()->getSession()->getSessionCart($this->getDispatcher())->getCartItems()->count(),
                ],
                $resultData
            )
        );

        return new Response($renderedTemplate);
    }

    /**
     *
     * This method is call on Payment loop.
     *
     * If you return true, the payment method will de display
     * If you return false, the payment method will not be display
     *
     * @return boolean
     */
    public function isValidPayment()
    {
        $valid = false;

        // CHeck if the module has been configured.
        if (! empty(PayzenEmbedded::getConfigValue('site_id'))) {
            $mode = self::getConfigValue('mode', false);

            // If we're in test / restricted production mode, do not display module on the front office, except for allowed IP addresses.
            if ('TEST' === $mode || $mode === 'PRODUCTION_RESTRICTED') {
                $raw_ips = explode("\n", self::getConfigValue('allowed_ip_list', ''));

                $allowed_client_ips = [];

                foreach ($raw_ips as $ip) {
                    $allowed_client_ips[] = trim($ip);
                }

                $client_ip = $this->getRequest()->getClientIp();

                $valid = in_array($client_ip, $allowed_client_ips);
            } elseif ('PRODUCTION' == $mode) {
                $valid = true;
            }

            if ($valid) {
                // Check if total order amount is in the module's limits
                $valid = $this->checkMinMaxAmount();
            }
        }

        return $valid;
    }


    /**
     * Check if total order amount is in the module's limits
     *
     * @return bool true if the current order total is within the min and max limits
     */
    protected function checkMinMaxAmount()
    {
        // Check if total order amount is between the module's limits
        $order_total = $this->getCurrentOrderTotalAmount();

        $min_amount = self::getConfigValue('minimum_amount', 0);
        $max_amount = self::getConfigValue('maximum_amount', 0);

        return $order_total > 0 && ($min_amount <= 0 || $order_total >= $min_amount) && ($max_amount <= 0 || $order_total <= $max_amount);
    }

    /**
     * @param ConnectionInterface|null $con
     * @throws \Exception
     */
    public function postActivation(ConnectionInterface $con = null)
    {
        $languages = LangQuery::create()->find();

        if (null === MessageQuery::create()->findOneByName(self::CONFIRMATION_MESSAGE_NAME)) {
            $message = new Message();
            $message
                ->setName(self::CONFIRMATION_MESSAGE_NAME)
                ->setHtmlLayoutFileName('')
                ->setHtmlTemplateFileName(self::CONFIRMATION_MESSAGE_NAME.'.html')
                ->setTextLayoutFileName('')
                ->setTextTemplateFileName(self::CONFIRMATION_MESSAGE_NAME.'.txt')
            ;

            foreach ($languages as $language) {
                /** @var Lang $language */
                $locale = $language->getLocale();

                $message->setLocale($locale);

                $message->setTitle(
                    Translator::getInstance()->trans('Order payment confirmation', [], $locale)
                );

                $message->setSubject(
                    Translator::getInstance()->trans('Order {$order_ref} payment confirmation', [], $locale)
                );
            }

            $message->save();
        }

        /* Deploy the module's image */
        $module = $this->getModuleModel();

        if (ModuleImageQuery::create()->filterByModule($module)->count() == 0) {
            $this->deployImageFolder($module, sprintf('%s/images', __DIR__), $con);
        }
    }

    public function preActivation(ConnectionInterface $con = null)
    {
        $database = new Database($con);
        $database->insertSql(null, array(__DIR__ . '/Config/create.sql'));

        return true;
    }

    public function destroy(ConnectionInterface $con = null, $deleteModuleData = false)
    {
        if ($deleteModuleData) {
            $database = new Database($con);

            $database->insertSql(null, array(__DIR__ . '/Config/destroy.sql'));
        }
    }
}
