$(function () {
  const $modal = $('#new-project-modal');

  function openModal() { $modal.prop('hidden', false); }
  function closeModal() { $modal.prop('hidden', true); $('#upload-status').text('').removeClass('error'); }

  $('#btn-new-project, #btn-new-project-2').on('click', openModal);
  $('#btn-cancel-new').on('click', closeModal);
  $modal.on('click', function (e) { if (e.target === this) closeModal(); });

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
