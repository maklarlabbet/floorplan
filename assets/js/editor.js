$(function () {
  const projectId = $('body').data('project-id');
  const svg = document.getElementById('floorplan-svg');
  const canvas = document.getElementById('annotation-canvas');
  let versions = [];
  let activeVersion = null;
  let pendingNotePos = null;

  function showLoading(text) {
    $('#loading-text').text(text || 'Claude is drafting your floorplan…');
    $('#loading-overlay').prop('hidden', false);
  }
  function hideLoading() { $('#loading-overlay').prop('hidden', true); }

  function loadProject() {
    $.get('api/get_project.php', { project_id: projectId }, function (res) {
      if (!res.ok) { alert(res.error || 'Failed to load project'); return; }
      versions = res.versions;
      renderVersionList();
      const readyVersions = versions.filter(v => v.status === 'ready' && v.floorplan);
      const latest = readyVersions[readyVersions.length - 1];
      if (latest) {
        selectVersion(latest.id);
      } else {
        const processing = versions.find(v => v.status === 'processing');
        if (processing) pollAnalysis(processing.id);
      }
      const firstUpload = versions.find(v => v.image_url);
      if (firstUpload) {
        $('#source-thumb').html('<img src="' + firstUpload.image_url + '" alt="original upload">');
      }
    }, 'json');
  }

  function renderVersionList() {
    const $list = $('#version-list').empty();
    versions.forEach(v => {
      const badgeClass = v.status === 'failed' ? 'failed' : (v.source_type === 'ai_generated' ? 'ai' : '');
      const badgeText = v.status === 'failed' ? 'failed' : (v.source_type === 'ai_generated' ? 'AI edit' : 'upload');
      const $item = $('<div class="version-item">')
        .attr('data-id', v.id)
        .toggleClass('active', activeVersion && activeVersion.id === v.id)
        .append($('<span>').text('v' + v.version_number))
        .append($('<span class="version-badge ' + badgeClass + '">').text(badgeText))
        .on('click', () => selectVersion(v.id));
      $list.append($item);
    });
  }

  function selectVersion(versionId) {
    const v = versions.find(x => x.id === versionId);
    if (!v) return;
    activeVersion = v;
    renderVersionList();
    DrawTool.clear();
    if (v.status === 'processing') {
      $('#empty-overlay').prop('hidden', true);
      pollAnalysis(v.id);
      return;
    }
    if (v.status === 'failed') {
      renderFloorplan(svg, null);
      $('#empty-overlay').prop('hidden', false).find('p').text('Analysis failed: ' + (v.error_message || 'unknown error'));
      return;
    }
    if (v.floorplan) {
      $('#empty-overlay').prop('hidden', true);
      renderFloorplan(svg, v.floorplan);
    } else {
      renderFloorplan(svg, null);
      $('#empty-overlay').prop('hidden', false).find('p').text('No floorplan data yet for this version.');
    }
  }

  function pollAnalysis(versionId) {
    showLoading('Claude is reading your floorplan and redrawing it…');
    $.post('api/analyze.php', { version_id: versionId }, function (res) {
      hideLoading();
      if (!res.ok) {
        alert('Analysis failed: ' + res.error);
        loadProject();
        return;
      }
      loadProject();
    }, 'json').fail(function (xhr) {
      hideLoading();
      alert('Analysis failed: ' + (xhr.responseJSON?.error || xhr.statusText));
    });
  }

  // ---- Toolbar ----
  $('.tool-btn').on('click', function () {
    $('.tool-btn').removeClass('active');
    $(this).addClass('active');
    DrawTool.setTool($(this).data('tool'));
  });
  $('input[name=color]').on('change', function () { DrawTool.setColor($(this).val()); });
  $('#btn-undo-mark').on('click', () => DrawTool.undo());
  $('#btn-clear-marks').on('click', () => DrawTool.clear());

  $('#btn-download').on('click', function () {
    if (!activeVersion || !activeVersion.floorplan) return;
    const svgStr = serializeSvgForDownload(svg);
    const blob = new Blob([svgStr], { type: 'image/svg+xml' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'floorplan-v' + activeVersion.version_number + '.svg';
    a.click();
    URL.revokeObjectURL(url);
  });

  $('#btn-regenerate').on('click', function () {
    if (!activeVersion) return;
    const marks = DrawTool.getMarks();
    if (marks.length === 0) {
      alert('Draw a change or add a note first, then click Apply.');
      return;
    }
    showLoading('Claude is applying your changes…');
    $.ajax({
      url: 'api/regenerate.php',
      method: 'POST',
      contentType: 'application/json',
      data: JSON.stringify({ project_id: projectId, base_version_id: activeVersion.id, annotations: marks }),
      dataType: 'json',
      success: function (res) {
        hideLoading();
        if (!res.ok) { alert('Could not apply changes: ' + res.error); return; }
        loadProject();
      },
      error: function (xhr) {
        hideLoading();
        alert('Could not apply changes: ' + (xhr.responseJSON?.error || xhr.statusText));
      }
    });
  });

  // ---- Note popup ----
  function requestNote(pos, evt) {
    pendingNotePos = pos;
    const rect = canvas.getBoundingClientRect();
    const px = evt.clientX - rect.left;
    const py = evt.clientY - rect.top;
    $('#note-popup').css({ left: px + 'px', top: py + 'px' }).prop('hidden', false);
    $('#note-text').val('').focus();
  }
  $('#note-cancel').on('click', () => $('#note-popup').prop('hidden', true));
  $('#note-save').on('click', function () {
    const text = $('#note-text').val().trim();
    if (text && pendingNotePos) DrawTool.addNote(pendingNotePos, text);
    $('#note-popup').prop('hidden', true);
  });

  DrawTool.init(canvas, 1000, 700, requestNote);
  loadProject();
});
