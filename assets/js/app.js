$(function () {
  const $modal = $('#new-project-modal');

  $('#btn-test-connection').on('click', function () {
    const $btn = $(this);
    const $status = $('#connection-status');
    $btn.prop('disabled', true).text('Testing…');
    $status.removeClass('error').text('Sending a small test request to Claude…');

    $.get('api/test_connection.php', function (res) {
      $btn.prop('disabled', false).text('Test Claude connection');
      if (res.ok) {
        $status.removeClass('error').text('✓ ' + res.message + ' (Claude replied: "' + res.response + '")');
      } else {
        let msg = '✗ ' + res.error;
        if (res.debug) msg += '\n\n' + res.debug;
        $status.addClass('error').css('white-space', 'pre-wrap').text(msg);
      }
    }, 'json').fail(function (xhr) {
      $btn.prop('disabled', false).text('Test Claude connection');
      const res = xhr.responseJSON;
      let msg = '✗ ' + (res?.error || xhr.statusText);
      if (res?.debug) msg += '\n\n' + res.debug;
      $status.addClass('error').css('white-space', 'pre-wrap').text(msg);
    });
  });

  $('#btn-test-echo').on('click', function () {
    const $btn = $(this);
    const $status = $('#connection-status');
    $btn.prop('disabled', true).text('Testing…');
    $status.removeClass('error').css('white-space', 'pre-wrap')
      .text('Sending a small test POST to a public echo service (httpbin.org) — unrelated to Anthropic — to check whether request bodies survive the trip at all from this server…');

    $.get('api/test_echo.php', function (res) {
      $btn.prop('disabled', false).text('Test raw HTTPS POST');
      const lines = [];
      lines.push('Sent: ' + res.sent);
      lines.push('');
      lines.push('cURL: ' + (res.curl.ok ? (res.curl.matches ? '✓ echoed back correctly' : '✗ echoed but MISMATCHED — body corrupted in transit: "' + res.curl.echoed + '"') : '✗ ' + res.curl.error));
      lines.push('Streams: ' + (res.streams.ok ? (res.streams.matches ? '✓ echoed back correctly' : '✗ echoed but MISMATCHED — body corrupted in transit: "' + res.streams.echoed + '"') : '✗ ' + res.streams.error));
      lines.push('');
      if ((res.curl.ok && res.curl.matches) || (res.streams.ok && res.streams.matches)) {
        lines.push('→ At least one transport delivers POST bodies correctly to an unrelated site. If Claude API calls still fail, the issue is specific to reaching api.anthropic.com — worth asking your host if they block/inspect traffic to that domain specifically.');
      } else {
        lines.push('→ Neither transport delivered the body correctly to an unrelated site either. This points to a network-level issue on this server (firewall, proxy, or security appliance) affecting outbound HTTPS POST bodies in general — this is worth raising directly with your hosting provider\'s support team, with this exact result.');
      }
      $status.removeClass('error').css('white-space', 'pre-wrap').text(lines.join('\n'));
    }, 'json').fail(function (xhr) {
      $btn.prop('disabled', false).text('Test raw HTTPS POST');
      $status.addClass('error').text('✗ Could not run the test: ' + xhr.statusText);
    });
  });

  function openModal() { $modal.prop('hidden', false); }
  function closeModal() { $modal.prop('hidden', true); $('#upload-status').text('').removeClass('error'); }

  $('#btn-new-project, #btn-new-project-2').on('click', openModal);
  $('#btn-cancel-new').on('click', closeModal);
  $modal.on('click', function (e) { if (e.target === this) closeModal(); });

  $(document).on('click', '.project-card-delete', function (e) {
    e.preventDefault();
    e.stopPropagation();
    const $card = $(this).closest('.project-card');
    const id = $(this).data('id');
    const name = $card.find('h3').text();
    if (!confirm('Delete "' + name + '"? This removes all its versions and the uploaded image permanently.')) return;

    $.ajax({
      url: 'api/delete_project.php',
      method: 'POST',
      contentType: 'application/json',
      data: JSON.stringify({ project_id: id }),
      dataType: 'json',
      success: function (res) {
        if (!res.ok) { alert(res.error || 'Could not delete project.'); return; }
        $card.fadeOut(150, function () {
          $card.remove();
          if ($('.project-card').length === 0) location.reload();
        });
      },
      error: function (xhr) {
        alert(xhr.responseJSON?.error || 'Could not delete project.');
      }
    });
  });

  $('#project-image').on('change', function () {
    const file = this.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = e => {
      $('#upload-preview-img').attr('src', e.target.result);
      $('#upload-preview').prop('hidden', false);
    };
    reader.readAsDataURL(file);
  });

  $('#new-project-form').on('submit', function (e) {
    e.preventDefault();
    const $btn = $('#btn-submit-new');
    const formData = new FormData(this);
    $btn.prop('disabled', true).text('Uploading…');
    $('#upload-status').removeClass('error').text('Uploading image…');

    $.ajax({
      url: 'api/create_project.php',
      method: 'POST',
      data: formData,
      processData: false,
      contentType: false,
      dataType: 'json',
      success: function (res) {
        if (!res.ok) {
          $('#upload-status').addClass('error').text(res.error || 'Upload failed.');
          $btn.prop('disabled', false).text('Upload & Analyze');
          return;
        }
        $('#upload-status').text('Claude is analyzing the floorplan — this can take up to a minute…');
        // Kick off analysis, then jump straight into the editor; the editor will poll/show status too.
        $.post('api/analyze.php', { version_id: res.version_id }, function () {
          window.location.href = 'editor.php?project_id=' + res.project_id;
        }, 'json').fail(function () {
          // Even if this particular call times out client-side, go to the editor —
          // the version is stored and can be retried there.
          window.location.href = 'editor.php?project_id=' + res.project_id;
        });
      },
      error: function (xhr) {
        $('#upload-status').addClass('error').text(xhr.responseJSON?.error || 'Upload failed.');
        $btn.prop('disabled', false).text('Upload & Analyze');
      }
    });
  });
});
