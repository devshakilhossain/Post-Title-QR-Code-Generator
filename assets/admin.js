(function ($) {
  'use strict';

  function setStatus($panel, message, isError) {
    $panel.find('.rs-qr-status')
      .toggleClass('is-error', Boolean(isError))
      .text(message || '');
  }

  function setLoading($panel, isLoading) {
    var $button = $panel.find('.rs-qr-generate');
    $panel.toggleClass('is-loading', isLoading);
    $button.prop('disabled', isLoading);

    if (isLoading) {
      $button.data('original-label', $button.text());
      $button.text(RSQRCodeGenerator.i18n.generating);
    } else {
      $button.text($button.data('original-label') || $button.text());
    }
  }

  function getCurrentPostTitle() {
    if (window.wp && wp.data && wp.data.select('core/editor')) {
      return wp.data.select('core/editor').getEditedPostAttribute('title') || '';
    }

    return $('#title').val() || '';
  }

  $(document).on('click', '.rs-qr-generate', function () {
    var $panel = $(this).closest('.rs-qr-panel');
    var postId = $panel.data('post-id');

    setStatus($panel, '', false);
    setLoading($panel, true);

    $.ajax({
      url: RSQRCodeGenerator.ajaxUrl,
      method: 'POST',
      dataType: 'json',
      data: {
        action: RSQRCodeGenerator.action,
        nonce: RSQRCodeGenerator.nonce,
        postId: postId,
        title: getCurrentPostTitle()
      }
    }).done(function (response) {
      if (!response || !response.success) {
        setStatus($panel, RSQRCodeGenerator.i18n.error, true);
        return;
      }

      var data = response.data;
      var $previewWrap = $panel.find('.rs-qr-preview-wrap');
      var $download = $panel.find('.rs-qr-download');
      var $button = $panel.find('.rs-qr-generate');

      $previewWrap
        .removeClass('is-empty')
        .html('<img class="rs-qr-preview" alt="Post title QR code">');

      $previewWrap.find('img').attr('src', data.url);
      $download
        .removeClass('is-hidden')
        .attr('href', data.downloadUrl || data.url)
        .attr('download', data.downloadFilename || '')
        .text(data.downloadLabel);
      $button.data('original-label', data.buttonLabel).text(data.buttonLabel);
      $panel.find('.rs-qr-note').remove();
      setStatus($panel, data.message, false);
    }).fail(function (xhr) {
      var message = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message
        ? xhr.responseJSON.data.message
        : RSQRCodeGenerator.i18n.error;

      setStatus($panel, message, true);
    }).always(function () {
      setLoading($panel, false);
    });
  });
})(jQuery);
