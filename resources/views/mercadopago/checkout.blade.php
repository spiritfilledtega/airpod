<?php
	
use Carbon\Carbon;
	
	
	$environment = get_settings('mercadopago_environment');
	if($environment == 'test')
	{
	$access_token = get_settings('mercadopago_test_access_token');
	
	$public_key = get_settings('mercadopago_test_public_key');
	}else
	{
		$access_token = get_settings('mercadopago_live_access_token');
		
		$public_key = get_settings('mercadopago_live_public_key');
	}


	MercadoPago\SDK::setAccessToken($access_token);

	$preference = new MercadoPago\Preference();

    # Building an item

    $item = new MercadoPago\Item();
    $item->id = "00001";
    $item->title = "add-money-to-wallet"; 
    $item->quantity = 1;
    $item->unit_price = request()->amount;

    $preference->items = array($item);


    $current_timestamp = Carbon::now()->timestamp;

    $preference->external_reference = $current_timestamp.'----'.request()->user_id.'----'.request()->request_for.'----'.request()->amount;


    // $preference->back_urls=array(
    // 	"success"=>'https://tagxi-server.ondemandappz.com/success',
    // 	"failure"=>'https://tagxi-server.ondemandappz.com/failure',
    // 	"pending"=>'https://tagxi-server.ondemandappz.com/failure'
    // );

    $preference->back_urls=array(
    	"success"=>env('APP_URL').'/mercadopago-success',
    	"failure"=>env('APP_URL').'/failure',
    	"pending"=>env('APP_URL').'/pending'
    );
    
    $preference->save();


?>



<!DOCTYPE html>
<html>
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Mercadopago-Checkout</title>
	<!-- <style type="text/css">
		.container{
			display: flex;
			align-items: start;
			justify-content: start;
			margin-top: 150px;
		}
	</style> -->
</head>
<style>
	  body {
        text-align: center;
        padding: 40px 0;
        /* background: #EBF0F5; */
      }
	  .card {
        background: white;
        padding: 60px;
        border-radius: 4px;
        box-shadow: 0 2px 3px #C8D0D8;
        display: inline-block;
        margin: 0 auto;
      }
</style>
<body>
<div class="card">
<div class="amount-display text-center"> $ <?php echo request()->amount; ?></div>

	<div class="contenedor-btn "></div>
	</div>
	<script src="https://sdk.mercadopago.com/js/v2"></script>

	<script>
		var public_key='<?php echo $public_key; ?>';

		const mp = new MercadoPago(public_key,{
			
		});

		const checkout = mp.checkout({

			preference:{
				id:'<?php echo $preference->id; ?>'
			},
			render:{
				container:'.contenedor-btn',
				label:'Paynow',
			}
		})
	</script>
</body>
</html>