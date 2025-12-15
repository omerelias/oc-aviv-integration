(function($){
	$(function(){
		var $table = $('.oc-aviv-pos-mapping');
		if(!$table.length) return;

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
	});
})(jQuery);

