# Сервис для работы с платежной системы FreeKassa через API в laravel
### Установка
*Создать новый сервис-провайдер для класса FreeKassaService
### Пример использования
```php
class FreekassaControler extends Controller
{
    public function createOrder(Request $requst, FreeKassaService $freekassa)
    {
        //Создаем заказ на freekassa и получаем ссылку на оплату
        $freekassa->getPayUrl($requst);

    }

    public function handlePayment(Request $request, FreeKassaService $freekassa)
    {
        //обработчик оплаты
        $freekassa->handler($request);
    }
}
```
