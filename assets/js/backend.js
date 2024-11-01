'use strict';

(function($) {
  $(function() {
    wpcsl_source_init();
    wpcsl_build_label();
    wpcsl_terms_init();
    wpcsl_enhanced_select();
    wpcsl_combination_init();
    wpcsl_combination_terms_init();
    wpcsl_sortable();
  });

  $(document).on('change', '.wpcsl_source_selector', function() {
    var $this = $(this);
    var type = $this.data('type');
    var $rule = $this.closest('.wpcsl_rule');

    wpcsl_source_init(type, $rule);
    wpcsl_build_label($rule);
    wpcsl_terms_init();
  });

  $(document).on('change, keyup', '.wpcsl_rule_name_val', function() {
    var name = $(this).val();

    $(this).
        closest('.wpcsl_rule').
        find('.wpcsl_rule_name').
        html(name.replace(/(<([^>]+)>)/ig, ''));
  });

  $(document).on('change', '.wpcsl_terms', function() {
    var $this = $(this);
    var type = $this.data('type');
    var apply = $(this).
        closest('.wpcsl_rule').
        find('.wpcsl_source_selector_' + type).
        val();

    $this.data(apply, $this.val().join());
  });

  $(document).on('change', '.wpcsl_combination_selector', function() {
    wpcsl_combination_init();
    wpcsl_combination_terms_init();
  });

  $(document).on('click touch', '.wpcsl_combination_remove', function() {
    $(this).closest('.wpcsl_combination').remove();
  });

  $(document).on('click touch', '.wpcsl_rule_heading', function(e) {
    if ($(e.target).closest('.wpcsl_rule_remove').length === 0 &&
        $(e.target).closest('.wpcsl_rule_duplicate').length === 0) {
      $(this).closest('.wpcsl_rule').toggleClass('active');
    }
  });

  $(document).on('click touch', '.wpcsl_new_combination', function(e) {
    var $combinations = $(this).
        closest('.wpcsl_tr').
        find('.wpcsl_combinations');
    var key = $(this).
        closest('.wpcsl_rule').data('key');
    var name = $(this).data('name');
    var type = $(this).data('type');
    var data = {
      action: 'wpcsl_add_combination',
      nonce: wpcsl_vars.wpcsl_nonce,
      key: key,
      name: name,
      type: type,
    };

    $.post(ajaxurl, data, function(response) {
      $combinations.append(response);
      wpcsl_combination_init();
      wpcsl_combination_terms_init();
    });

    e.preventDefault();
  });

  $(document).on('click touch', '.wpcsl_new_rule', function(e) {
    e.preventDefault();
    $('.wpcsl_rules').addClass('wpcsl_rules_loading');

    var name = $(this).data('name');
    var data = {
      action: 'wpcsl_add_rule', nonce: wpcsl_vars.wpcsl_nonce, name: name,
    };

    $.post(ajaxurl, data, function(response) {
      $('.wpcsl_rules').append(response);
      wpcsl_source_init();
      wpcsl_build_label();
      wpcsl_terms_init();
      wpcsl_enhanced_select();
      wpcsl_combination_init();
      wpcsl_combination_terms_init();
      $('.wpcsl_rules').removeClass('wpcsl_rules_loading');
    });
  });

  $(document).on('click touch', '.wpcsl_rule_remove', function(e) {
    e.preventDefault();

    if (confirm('Are you sure?')) {
      $(this).closest('.wpcsl_rule').remove();
    }
  });

  $(document).on('click touch', '.wpcsl_rule_duplicate', function(e) {
    e.preventDefault();
    $('.wpcsl_rules').addClass('wpcsl_rules_loading');

    var $rule = $(this).closest('.wpcsl_rule');
    var rule_data = $rule.find('input, select, button, textarea').
        serialize() || 0;
    var name = $(this).data('name');
    var data = {
      action: 'wpcsl_add_rule',
      nonce: wpcsl_vars.wpcsl_nonce,
      name: name,
      rule_data: rule_data,
    };

    $.post(ajaxurl, data, function(response) {
      $(response).insertAfter($rule);
      wpcsl_source_init();
      wpcsl_build_label();
      wpcsl_terms_init();
      wpcsl_enhanced_select();
      wpcsl_combination_init();
      wpcsl_combination_terms_init();
      $('.wpcsl_rules').removeClass('wpcsl_rules_loading');
    });
  });

  $(document).on('click touch', '.wpcsl_expand_all', function(e) {
    e.preventDefault();

    $('.wpcsl_rule').addClass('active');
  });

  $(document).on('click touch', '.wpcsl_collapse_all', function(e) {
    e.preventDefault();

    $('.wpcsl_rule').removeClass('active');
  });

  $(document).on('click touch', '.wpcsl_conditional_remove', function(e) {
    e.preventDefault();

    if (confirm('Are you sure?')) {
      $(this).closest('.wpcsl_conditional_item').remove();
    }
  });

  $(document).on('click touch', '.wpcsl_import_export', function(e) {
    var name = $(this).data('name');

    if (!$('#wpcsl_import_export').length) {
      $('body').append('<div id=\'wpcsl_import_export\'></div>');
    }

    $('#wpcsl_import_export').html('Loading...');

    $('#wpcsl_import_export').dialog({
      minWidth: 460,
      title: 'Import/Export',
      modal: true,
      dialogClass: 'wpc-dialog',
      open: function() {
        $('.ui-widget-overlay').bind('click', function() {
          $('#wpcsl_import_export').dialog('close');
        });
      },
    });

    var data = {
      action: 'wpcsl_import_export', name: name, nonce: wpcsl_vars.wpcsl_nonce,
    };

    $.post(ajaxurl, data, function(response) {
      $('#wpcsl_import_export').html(response);
    });

    e.preventDefault();
  });

  $(document).on('click touch', '.wpcsl_import_export_save', function(e) {
    var name = $(this).data('name');

    if (confirm('Are you sure?')) {
      $(this).addClass('disabled');

      var rules = $('.wpcsl_import_export_data').val();
      var data = {
        action: 'wpcsl_import_export_save',
        nonce: wpcsl_vars.wpcsl_nonce,
        rules: rules,
        name: name,
      };

      $.post(ajaxurl, data, function(response) {
        location.reload();
      });
    }
  });

  function wpcsl_terms_init() {
    $('.wpcsl_terms').each(function() {
      var $this = $(this);
      var type = $this.data('type');
      var apply = $this.closest('.wpcsl_rule').
          find('.wpcsl_source_selector_' + type).
          val();

      $this.selectWoo({
        ajax: {
          url: ajaxurl, dataType: 'json', delay: 250, data: function(params) {
            return {
              q: params.term, action: 'wpcsl_search_term', taxonomy: apply,
            };
          }, processResults: function(data) {
            var options = [];
            if (data) {
              $.each(data, function(index, text) {
                options.push({id: text[0], text: text[1]});
              });
            }
            return {
              results: options,
            };
          }, cache: true,
        }, minimumInputLength: 1,
      });

      if (apply !== 'all' && apply !== 'products' && apply !== 'combination') {
        // for terms only
        if ($this.data(apply) !== undefined && $this.data(apply) !== '') {
          $this.val(String($this.data(apply)).split(',')).change();
        } else {
          $this.val([]).change();
        }
      }
    });
  }

  function wpcsl_combination_init() {
    $('.wpcsl_combination_selector').each(function() {
      var $this = $(this);
      var $combination = $this.closest('.wpcsl_combination');
      var val = $this.val();

      if (val === 'same') {
        $combination.find('.wpcsl_combination_compare_wrap').hide();
        $combination.find('.wpcsl_combination_val_wrap').hide();
        $combination.find('.wpcsl_combination_number_compare_wrap').hide();
        $combination.find('.wpcsl_combination_number_val_wrap').hide();
        $combination.find('.wpcsl_combination_same_wrap').show();
      } else if (val === 'price') {
        $combination.find('.wpcsl_combination_same_wrap').hide();
        $combination.find('.wpcsl_combination_compare_wrap').hide();
        $combination.find('.wpcsl_combination_val_wrap').hide();
        $combination.find('.wpcsl_combination_number_compare_wrap').show();
        $combination.find('.wpcsl_combination_number_val_wrap').show();
      } else {
        $combination.find('.wpcsl_combination_same_wrap').hide();
        $combination.find('.wpcsl_combination_number_compare_wrap').hide();
        $combination.find('.wpcsl_combination_number_val_wrap').hide();
        $combination.find('.wpcsl_combination_compare_wrap').show();
        $combination.find('.wpcsl_combination_val_wrap').show();
      }
    });
  }

  function wpcsl_combination_terms_init() {
    $('.wpcsl_apply_terms').each(function() {
      var $this = $(this);
      var taxonomy = $this.closest('.wpcsl_combination').
          find('.wpcsl_combination_selector').
          val();

      $this.selectWoo({
        ajax: {
          url: ajaxurl, dataType: 'json', delay: 250, data: function(params) {
            return {
              q: params.term, action: 'wpcsl_search_term', taxonomy: taxonomy,
            };
          }, processResults: function(data) {
            var options = [];
            if (data) {
              $.each(data, function(index, text) {
                options.push({id: text[0], text: text[1]});
              });
            }
            return {
              results: options,
            };
          }, cache: true,
        }, minimumInputLength: 1,
      });
    });
  }

  function wpcsl_source_init(type = 'apply', $rule) {
    if (typeof $rule !== 'undefined') {
      var apply = $rule.find('.wpcsl_source_selector_' + type).
          find(':selected').
          val();
      var text = $rule.find('.wpcsl_source_selector_' + type).
          find(':selected').
          text();

      $rule.find('.wpcsl_' + type + '_text').text(text);
      $rule.find('.hide_' + type).hide();
      $rule.find('.show_if_' + type + '_' + apply).show();
      $rule.find('.show_' + type).show();
      $rule.find('.hide_if_' + type + '_' + apply).hide();
    } else {
      $('.wpcsl_source_selector').each(function(e) {
        var type = $(this).data('type');
        var $rule = $(this).closest('.wpcsl_rule');
        var apply = $(this).find(':selected').val();
        var text = $(this).find(':selected').text();

        $rule.find('.wpcsl_' + type + '_text').text(text);
        $rule.find('.hide_' + type).hide();
        $rule.find('.show_if_' + type + '_' + apply).show();
        $rule.find('.show_' + type).show();
        $rule.find('.hide_if_' + type + '_' + apply).hide();
      });
    }
  }

  function wpcsl_sortable() {
    $('.wpcsl_rules').sortable({handle: '.wpcsl_rule_move'});
  }

  function wpcsl_build_label($rule) {
    if (typeof $rule !== 'undefined') {
      var apply = $rule.find('.wpcsl_source_selector_apply').
          find('option:selected').
          text();
      var get = $rule.find('.wpcsl_source_selector_get').
          find('option:selected').
          text();

      $rule.find('.wpcsl_rule_apply_get').
          html('Apply for: ' + apply + ' | Linked products: ' + get);
    } else {
      $('.wpcsl_rule ').each(function() {
        var $this = $(this);
        var apply = $this.find('.wpcsl_source_selector_apply').
            find('option:selected').
            text();
        var get = $this.find('.wpcsl_source_selector_get').
            find('option:selected').
            text();

        $this.find('.wpcsl_rule_apply_get').
            html('Apply for: ' + apply + ' | Linked products: ' + get);
      });
    }
  }

  function wpcsl_enhanced_select() {
    $(document.body).trigger('wc-enhanced-select-init');
  }
})(jQuery);