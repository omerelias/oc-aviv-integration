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

		// Toggle "מקט לשליחה" field based on "send same SKU" checkbox.
		var $sameSkuCheckbox = $('#same_sku_enabled');
		if($sameSkuCheckbox.length){
			$sameSkuCheckbox.on('change', function(){
				$('.oc-aviv-same-sku-value-row').toggle(this.checked);
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
				
				// Check if order already exists
				if(resp.data && resp.data.already_exists){
					alert('ההזמנה כבר קיימת ב-Aviv POS: ' + (resp.data.message || 'Order already exists'));
				} else {
					alert('Order sent successfully!');
				}
			}).fail(function(xhr){
				var err = 'Error: ' + xhr.status;
				$payloadBox.text(err);
				$requestBox.text(err);
				$responseBox.text(err);
				alert('Failed to send order: ' + err);
			});
		});

		// Clear webhook logs button
		$('#oc_aviv_clear_webhook_logs').on('click', function(e){
			e.preventDefault();
			if(!confirm('האם אתה בטוח שברצונך לנקות את כל לוג ה-Webhook?')){
				return;
			}
			var nonce = $('#oc_aviv_clear_logs_nonce').val();
			var $button = $(this);
			var originalText = $button.text();
			$button.prop('disabled', true).text('מנקה...');
			$.post(ajaxurl, {
				action: 'oc_aviv_pos_clear_webhook_logs',
				nonce: nonce
			}).done(function(resp){
				if(resp && resp.success){
					alert('הלוג נוקה בהצלחה');
					location.reload();
				} else {
					alert('שגיאה בניקוי הלוג');
					$button.prop('disabled', false).text(originalText);
				}
			}).fail(function(xhr){
				alert('שגיאה בניקוי הלוג: ' + xhr.status);
				$button.prop('disabled', false).text(originalText);
			});
		});

		// Import products button
		$('#oc_aviv_import_products').on('click', function(e){
			e.preventDefault();
			var filename = $('#oc_aviv_import_file_select').val();
			if(!filename){
				alert('אנא בחר קובץ מהרשימה');
				return;
			}
			if(!confirm('האם אתה בטוח שברצונך לעדכן מוצרים מהקובץ ' + filename + '?')){
				return;
			}
			var nonce = $('#oc_aviv_import_nonce').val();
			var $button = $(this);
			var $result = $('#oc_aviv_import_result');
			var originalText = $button.text();
			$button.prop('disabled', true).text('מעבד...');
			$result.html('<p style="color: #2271b1;">מעבד קובץ...</p>');
			$.post(ajaxurl, {
				action: 'oc_aviv_pos_import_products',
				filename: filename,
				nonce: nonce
			}).done(function(resp){
				if(!resp || !resp.success){
					var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'שגיאה';
					$result.html('<div style="background: #fef2f2; border: 1px solid #f8d7da; padding: 12px; border-radius: 4px; color: #842029;"><strong>שגיאה:</strong> ' + msg + '</div>');
					$button.prop('disabled', false).text(originalText);
					return;
				}
				var data = resp.data || {};
				var updated = data.updated || 0;
				var notFound = data.not_found || 0;
				var errors = data.errors || [];
				var message = 'ייבוא הושלם בהצלחה!<br>';
				message += '<strong>עודכנו:</strong> ' + updated + ' מוצרים<br>';
				if(notFound > 0){
					message += '<strong>לא נמצאו:</strong> ' + notFound + ' מוצרים<br>';
				}
				if(errors.length > 0){
					message += '<strong>שגיאות:</strong> ' + errors.length + '<br>';
				}
				$result.html('<div style="background: #d1e7dd; border: 1px solid #badbcc; padding: 12px; border-radius: 4px; color: #0f5132;">' + message + '</div>');
				$button.prop('disabled', false).text(originalText);
			}).fail(function(xhr){
				var err = 'שגיאה: ' + xhr.status;
				$result.html('<div style="background: #fef2f2; border: 1px solid #f8d7da; padding: 12px; border-radius: 4px; color: #842029;"><strong>שגיאה:</strong> ' + err + '</div>');
				$button.prop('disabled', false).text(originalText);
			});
		});
	});
})(jQuery);

