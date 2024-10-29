<section class="woocommerce-accept-bitcoin-payment-instructions">
	
	<h2 class="woocommerce-accept-bitcoin-payment-instructions__title"><?php _e('Payment instructions', 'accept-bitcoin') ?></h2>

		<?php 
		// If converted BTC amount is less than or equal to 0, we can assume some kind of conversion error.
		if( $btc_amount <= 0 ):
		?>

			<p><?php _e('Conversion to BTC failed.', 'accept-bitcoin'); ?></p>
			
		<?php else: ?>

			<img src="<?php echo $this->get_qr_code_url($btc_address, $btc_amount); ?>" alt="Bitcoin QR code">
			<p><?php printf( __('Pay <code>%s</code> BTC to <code>%s</code>.', 'accept-bitcoin'), $btc_amount, $btc_address ); ?></p>

		<?php endif; ?>

</section>