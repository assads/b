<?
/**
 * bitrix
 *
 * напоминание о отложенных товарах за последний месяц, которых нет в заказах
 *
 * запуск ProductReminder::Init()
 *
 */
class ProductReminder
{
    /**
     * получаем дату для выборки
     *
     * @return object
     */
    private static function GetDateStart()
    {
        return \Bitrix\Main\Type\DateTime::createFromTimestamp(strtotime('-1 month'));
    }

    /**
     * получаем юзеров у которых есть отложенные товары
     *
     * @return array
     */
    private static function GetUsers()
    {
        $users = array();
        $result_users = \Bitrix\Main\UserTable::getList(
            array(
                'order' => array(
                    'ID' => 'DESC',
                ),
                'filter' => array(
                    'ACTIVE'              => 'Y',
                    'BASKET.DELAY'        => 'Y',
                    '>BASKET.DATE_UPDATE' => self::GetDateStart()
                ),
                'select' => array(
                    'ID',
                    'EMAIL',
                    'LAST_NAME',
                    'NAME',
                    'SECOND_NAME',
                ),
                'runtime' => array(
                    new Bitrix\Main\Entity\ReferenceField(
                        'BASKET',
                        '\Bitrix\Sale\Internals\BasketTable',
                        array(
                           '=this.ID' => 'ref.FUSER_ID',
                        ),
                        array(
                           'join_type' => 'inner'
                        )
                    ),
                ),
            )
        );
        while ($user = $result_users->fetch())
        {
            $users[$user['ID']] = $user;
        }
        return $users;
    }

    /**
     * получаем товары юзеров которых нет в заказах
     *
     * @param array $IDS - ид пользователей
     *
     * @return array
     */
    private static function GetProducts($IDS)
    {
        $products      = array();
        $result_basket = \Bitrix\Sale\Internals\BasketTable::getList(
            array(
                'filter' => array(
                    'FUSER_ID'         => $IDS,
                    'DELAY'            => 'Y',
                    '>DATE_UPDATE'     => self::GetDateStart(),
                    '=ORDER.STATUS_ID' => false,
                ),
                'select' => array(
                    'ID',
                    'FUSER_ID',
                    'PRODUCT_ID',
                    'NAME',
                    'ORDER.STATUS_ID',
                ),
                'runtime' => array(
                    new Bitrix\Main\Entity\ReferenceField(
                        'ORDER',
                        '\Bitrix\Sale\Internals\OrderTable',
                        array(
                           '=this.ORDER_ID' => 'ref.ID',
                        ),
                        array(
                           'join_type' => 'left'
                        )
                    ),
                ),
            )
        );
        while ($row_basket = $result_basket->fetch())
        {
            $products[$row_basket['FUSER_ID']][$row_basket['PRODUCT_ID']] = $row_basket;
        }
        return $products;
    }

    public static function Init()
    {
        $res    = 0;
        $errors = array();
        if (!\Bitrix\Main\Loader::includeModule('sale'))
        {
            $errors[] = 'sale not connected class';
        }
        if (count($errors) > 0)
        {
            return implode(',', $errors);
        }
        else
        {
            $users = ProductReminder::GetUsers();
            if (count($users) > 0)
            {
                $ids      = array_keys($users);
                $products = self::GetProducts($ids);
                if (count($products) > 0)
                {
                    foreach ($products as $user_id => $products_ar)
                    {
                        $user      = $users[$user_id];
                        $user_name = array(
                            trim($user['LAST_NAME']),
                            trim($user['NAME']),
                            trim($user['SECOND_NAME']),
                        );
                        $user_name  = array_diff($user_name, array(''));
                        $user_name  = implode(' ', $user_name);
                        $user_email = $user['EMAIL'];
                        $prod_str   = array();
                        foreach ($products_ar as $prod)
                        {
                            $prod_str[] = $prod['NAME'];
                        }
                        $prod_str = array_diff($prod_str, array(''));
                        $prod_str = implode(', ', $prod_str);
                        /**
                         * lang файл если есть, перенести текста
                         *
                         * для теста так
                         *
                         * письма - событие и шаблон не известен
                         *
                         * как отправлять письма, немендленно или обычно ?
                         *
                         * Добрый день, $user_name В вашем вишлисте хранятся товары $prod_str
                         *
                         * отправка
                         */
                        // $ev = \Bitrix\Main\Mail\Event::send(
                        //     array(
                        //         'EVENT_NAME' => '',
                        //         'LID'        => SITE_ID,
                        //         'C_FIELDS'   => array(
                        //         ),
                        //     )
                        // );
                        if ($ev)
                        {
                            $res++;
                        }
                    }
                }
            }
        }
        return 'result ' . $res;
    }
}
?>