(function($){
	$(function(){
		var $table = $('.oc-aviv-pos-mapping');
		if($table.length){
			$table.on('click', '.remove-row', function(e){
				e.preventDefault();
				var $row = $(this).closest('tr');
				if($table.find('tbody tr').length > 1){
					$row.remove();
				}
			});

			$('.add-row').on('click', function(e){
				e.preventDefault();
				var $last = $table.find('tbody tr:last');
				var index = $table.find('tbody tr').length;
				var $clone = $last.clone();
				$clone.find('input, select').each(function(){
					var name = $(this).attr('name');
					if(name){
						name = name.replace(/\[\d+\]/, '['+index+']');
						$(this).attr('name', name);
					}
					if($(this).is('input')){
						$(this).val('');
					}
					if($(this).is('select')){
						$(this).val('');
					}
				});
				$table.find('tbody').append($clone);
			});
		}

		// Debug tool
		$('#oc_aviv_show_payload, #oc_aviv_show_request').on('click', function(e){
			e.preventDefault();
			var orderId = parseInt($('#oc_aviv_debug_order').val(), 10);
			if(!orderId){
				alert('Please enter Order ID');
				return;
			}
			var nonce = $('#oc_aviv_debug_nonce').val();
			var $payloadBox = $('#oc_aviv_debug_payload');
			var $requestBox = $('#oc_aviv_debug_request');
			var $responseBox = $('#oc_aviv_debug_response');
			var loadingText = window.ocAvivPosAdmin?.loading || 'Loading…';
			$payloadBox.text(loadingText);
			$requestBox.text(loadingText);
			$responseBox.text('');
			$.post(ajaxurl, {
				action: 'oc_aviv_pos_debug',
				order_id: orderId,
				nonce: nonce
			}).done(function(resp){
				if(!resp || !resp.success){
					var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Error';
					$payloadBox.text(msg);
					$requestBox.text(msg);
					$responseBox.text('');
					return;
				}
				if($(e.currentTarget).attr('id') === 'oc_aviv_show_payload'){
					$payloadBox.text(JSON.stringify(resp.data.payload, null, 2));
					$requestBox.text('');
					$responseBox.text('');
				}else{
					$payloadBox.text(JSON.stringify(resp.data.payload, null, 2));
					$requestBox.text(JSON.stringify(resp.data.request, null, 2));
					$responseBox.text(JSON.stringify(resp.data.response || { note: 'Not sent (debug only)' }, null, 2));
				}
			}).fail(function(xhr){
				var err = 'Error: ' + xhr.status;
				$payloadBox.text(err);
				$requestBox.text(err);
				$responseBox.text('');
			});
		});

		// Send order button
		$('#oc_aviv_send_order').on('click', function(e){
			e.preventDefault();
			var orderId = parseInt($('#oc_aviv_debug_order').val(), 10);
			if(!orderId){
				alert('Please enter Order ID');
				return;
			}
			if(!confirm('Are you sure you want to send this order to Aviv POS?')){
				return;
			}
			var nonce = $('#oc_aviv_send_nonce').val();
			var $payloadBox = $('#oc_aviv_debug_payload');
			var $requestBox = $('#oc_aviv_debug_request');
			var $responseBox = $('#oc_aviv_debug_response');
			var loadingText = window.ocAvivPosAdmin?.loading || 'Loading…';
			$payloadBox.text(loadingText);
			$requestBox.text(loadingText);
			$responseBox.text(loadingText);
			$.post(ajaxurl, {
				action: 'oc_aviv_pos_send_order',
				order_id: orderId,
				nonce: nonce
			}).done(function(resp){
				if(!resp || !resp.success){
					var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Error';
					$payloadBox.text(resp.data.payload ? JSON.stringify(resp.data.payload, null, 2) : msg);
					$requestBox.text(msg);
					$responseBox.text(msg);
					alert('Error: ' + msg);
					return;
				}
				$payloadBox.text(JSON.stringify(resp.data.payload, null, 2));
				$requestBox.text(JSON.stringify({ method: 'POST', url: 'Sent to Aviv POS', note: 'Request sent successfully' }, null, 2));
				$responseBox.text(JSON.stringify(resp.data.response || {}, null, 2));
				alert('Order sent successfully!');
			}).fail(function(xhr){
				var err = 'Error: ' + xhr.status;
				$payloadBox.text(err);
				$requestBox.text(err);
				$responseBox.text(err);
				alert('Failed to send order: ' + err);
			});
		});
	});
})(jQuery);

