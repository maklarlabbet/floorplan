$(function () {
  const projectId = $('body').data('project-id');
  const svg = document.getElementById('floorplan-svg');
  const canvas = document.getElementById('annotation-canvas');
  const stageEl = document.getElementById('stage');
  const stageWrapEl = document.querySelector('.stage-wrap');
  let currentCanvasW = 1000, currentCanvasH = 700;
  let versions = [];
  let activeVersion = null;
  let pendingNotePos = null;
  let hasPendingEdits = false;
  let wallPieceCounter = 0;
  let textLabelCounter = 0;
  let pendingTextLabel = null; // { mode: 'add', pos } | { mode: 'edit', existing }
  const ERASER_RADIUS = 10; // data-space units the eraser reaches on each side of the cursor

  // .stage must be sized so its on-screen box has EXACTLY the floorplan's own
  // canvas.width/height ratio — a floorplan isn't always 1000x700 (height is normalized but
  // tracks the source photo's real proportions). If .stage's ratio didn't match, the SVG
  // (preserveAspectRatio="xMidYMid meet") would letterbox inside it, but the click-tracking
  // <canvas> overlay has no notion of that letterboxing and maps clicks linearly across its
  // full box regardless — so clicks would silently land on the wrong floorplan coordinates.
  //
  // Rather than fix the ratio via CSS aspect-ratio (which only constrains width vs. height
  // proportionally and lets a tall floorplan grow past the visible area, forcing scrolling
  // and looking "zoomed in"), compute an explicit width+height here that fits entirely within
  // the visible stage-wrap area — like object-fit:contain — while still matching the ratio.
  function fitStage(w, h) {
    const availW = Math.min(stageWrapEl.clientWidth * 0.94, 1000);
    const availH = stageWrapEl.clientHeight * 0.94;
    const scale = Math.min(availW / w, availH / h);
    stageEl.style.width = Math.round(w * scale) + 'px';
    stageEl.style.height = Math.round(h * scale) + 'px';
  }

  function setStageSize(w, h) {
    currentCanvasW = w || 1000;
    currentCanvasH = h || 700;
    fitStage(currentCanvasW, currentCanvasH);
    DrawTool.setCanvasSize(currentCanvasW, currentCanvasH);
  }

  function pointInPolygon(px, py, polygon) {
    let inside = false;
    for (let i = 0, j = polygon.length - 1; i < polygon.length; j = i++) {
      const [xi, yi] = polygon[i], [xj, yj] = polygon[j];
      if (((yi > py) !== (yj > py)) && (px < (xj - xi) * (py - yi) / (yj - yi) + xi)) inside = !inside;
    }
    return inside;
  }

  // Checks the smallest/most specific elements first so a click near a door or wall
  // isn't swallowed by the (usually much larger) room polygon behind it.
  function findFloorplanHit(floorplan, pt) {
    const TOL = 10;
    for (const d of floorplan.doors || []) {
      if (Math.hypot(pt.x - d.x, pt.y - d.y) <= (d.width || 30) / 2 + TOL) return { type: 'doors', id: d.id };
    }
    for (const w of floorplan.windows || []) {
      if (Math.hypot(pt.x - w.x, pt.y - w.y) <= (w.width || 40) / 2 + TOL) return { type: 'windows', id: w.id };
    }
    for (const s of floorplan.stairs || []) {
      if (pt.x >= s.x - TOL && pt.x <= s.x + (s.width || 0) + TOL && pt.y >= s.y - TOL && pt.y <= s.y + (s.height || 0) + TOL) {
        return { type: 'stairs', id: s.id };
      }
    }
    for (const r of floorplan.rooms || []) {
      if (r.polygon && r.polygon.length >= 3 && pointInPolygon(pt.x, pt.y, r.polygon)) return { type: 'rooms', id: r.id };
    }
    return null;
  }

  // Hit-test against the actual rendered <text> bounding box (via getBBox) rather than
  // estimating character width — an estimate drifts from the real glyph metrics (font,
  // browser, zoom) and makes clicking an existing label to edit it unreliable.
  function findTextLabelHit(floorplan, pt) {
    const labels = floorplan.text_labels || [];
    const nodes = svg.querySelectorAll('.fp-text-label');
    const PAD = 6;
    for (let i = nodes.length - 1; i >= 0; i--) {
      const node = nodes[i];
      let box;
      try { box = node.getBBox(); } catch (e) { continue; }
      if (pt.x >= box.x - PAD && pt.x <= box.x + box.width + PAD &&
          pt.y >= box.y - PAD && pt.y <= box.y + box.height + PAD) {
        const label = labels.find(l => String(l.id) === node.getAttribute('data-label-id'));
        if (label) return label;
      }
    }
    return null;
  }

  // Trims the portion of a wall within `radius` of pt, like an eraser disc passing over it.
  // Returns the remaining wall piece(s) (0, 1, or 2) and whether anything was actually cut.
  function eraseWallSegment(w, pt, radius) {
    const dx = w.x2 - w.x1, dy = w.y2 - w.y1;
    const lenSq = dx * dx + dy * dy;
    if (lenSq === 0) return { pieces: [w], changed: false };

    const R = radius + (w.thickness || 6) / 2;
    const fx = w.x1 - pt.x, fy = w.y1 - pt.y;
    const a = lenSq;
    const b = 2 * (fx * dx + fy * dy);
    const c = fx * fx + fy * fy - R * R;
    const disc = b * b - 4 * a * c;
    if (disc < 0) return { pieces: [w], changed: false }; // eraser disc doesn't reach this wall

    const sqrtDisc = Math.sqrt(disc);
    let t1 = Math.max(0, Math.min(1, (-b - sqrtDisc) / (2 * a)));
    let t2 = Math.max(0, Math.min(1, (-b + sqrtDisc) / (2 * a)));
    if (t1 >= t2) return { pieces: [w], changed: false }; // erased range falls outside the actual segment

    const MIN_FRAC = 0.02; // drop leftover slivers too small to matter
    const pieces = [];
    if (t1 > MIN_FRAC) pieces.push({ ...w, id: 'w' + (++wallPieceCounter), x2: w.x1 + t1 * dx, y2: w.y1 + t1 * dy });
    if (t2 < 1 - MIN_FRAC) pieces.push({ ...w, id: 'w' + (++wallPieceCounter), x1: w.x1 + t2 * dx, y1: w.y1 + t2 * dy });
    return { pieces, changed: true };
  }

  function eraseAt(pos) {
    if (!activeVersion || !activeVersion.floorplan) return;
    const fp = activeVersion.floorplan;
    let changed = false;

    const nextWalls = [];
    (fp.walls || []).forEach(w => {
      const { pieces, changed: wallChanged } = eraseWallSegment(w, pos, ERASER_RADIUS);
      if (wallChanged) changed = true;
      nextWalls.push(...pieces);
    });
    fp.walls = nextWalls;

    // Doors/windows/stairs/rooms don't have a clean "partial" erase — a half-erased door or
    // stairs box doesn't mean anything, so touching one removes it entirely.
    const hit = findFloorplanHit(fp, pos);
    if (hit) {
      const arr = fp[hit.type];
      const idx = arr.findIndex(item => item.id === hit.id);
      if (idx !== -1) { arr.splice(idx, 1); changed = true; }
    }

    if (!changed) return;
    renderFloorplan(svg, fp);
    hasPendingEdits = true;
    $('#btn-save-edits').prop('hidden', false);
  }

  function saveEdits(onSaved) {
    $.ajax({
      url: 'api/save_edit.php',
      method: 'POST',
      contentType: 'application/json',
      data: JSON.stringify({ project_id: projectId, base_version_id: activeVersion.id, floorplan: activeVersion.floorplan }),
      dataType: 'json',
      success: function (res) {
        if (!res.ok) { alert('Could not save edits: ' + res.error); return; }
        hasPendingEdits = false;
        $('#btn-save-edits').prop('hidden', true);
        if (onSaved) onSaved(res.version_id);
      },
      error: function (xhr) {
        alert('Could not save edits: ' + (xhr.responseJSON?.error || xhr.statusText));
      }
    });
  }

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
      const badgeClass = v.status === 'failed' ? 'failed' : (v.source_type === 'ai_generated' ? 'ai' : (v.source_type === 'manual_edit' ? 'edited' : ''));
      const badgeText = v.status === 'failed' ? 'failed' : (v.source_type === 'ai_generated' ? 'AI edit' : (v.source_type === 'manual_edit' ? 'edited' : 'upload'));
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
    hasPendingEdits = false;
    $('#btn-save-edits').prop('hidden', true);
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
      setStageSize(v.floorplan.canvas && v.floorplan.canvas.width, v.floorplan.canvas && v.floorplan.canvas.height);
      renderFloorplan(svg, v.floorplan);
    } else {
      setStageSize(1000, 700);
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

  $('#btn-notes').on('click', function () {
    const notes = (activeVersion && activeVersion.floorplan && activeVersion.floorplan.notes) || [];
    const $list = $('#notes-list').empty();
    if (notes.length === 0) {
      $list.append($('<p class="form-note">').text('No notes for this version.'));
    } else {
      notes.forEach(n => $list.append($('<p>').text(n.text || '')));
    }
    $('#notes-modal').prop('hidden', false);
  });
  $('#notes-close').on('click', () => $('#notes-modal').prop('hidden', true));

  $('#btn-view-json').on('click', function () {
    const $view = $('#json-view');
    if (!$view.prop('hidden')) { $view.prop('hidden', true); return; }
    if (!activeVersion || !activeVersion.floorplan) {
      alert('No floorplan JSON for the selected version yet.');
      return;
    }
    $view.val(JSON.stringify(activeVersion.floorplan, null, 2)).prop('hidden', false);
    $view.trigger('focus').trigger('select');
  });

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

  $('#btn-save-edits').on('click', () => saveEdits(() => loadProject()));

  $('#btn-regenerate').on('click', function () {
    if (!activeVersion) return;
    const marks = DrawTool.getMarks();
    if (marks.length === 0 && !hasPendingEdits) {
      alert('Draw a change, add a note, or erase something first, then click Apply.');
      return;
    }

    function callRegenerate(baseVersionId) {
      if (marks.length === 0) { loadProject(); return; } // erasures already saved, nothing more for Claude to do
      showLoading('Claude is applying your changes…');
      $.ajax({
        url: 'api/regenerate.php',
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify({ project_id: projectId, base_version_id: baseVersionId, annotations: marks }),
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
    }

    // Erasures only live in the browser until saved — persist them first so Claude
    // regenerates from the up-to-date floorplan, not the stale pre-erase version.
    if (hasPendingEdits) saveEdits(callRegenerate);
    else callRegenerate(activeVersion.id);
  });

  // ---- Note popup ----
  // #note-popup/#text-label-popup are absolutely positioned but sit outside any positioned
  // ancestor, so their left/top are relative to the viewport, not the canvas — use the
  // click's viewport coordinates directly rather than subtracting the canvas's own offset.
  function requestNote(pos, evt) {
    pendingNotePos = pos;
    $('#note-popup').css({ left: evt.clientX + 'px', top: evt.clientY + 'px' }).prop('hidden', false);
    $('#note-text').val('').focus();
  }
  $('#note-cancel').on('click', () => $('#note-popup').prop('hidden', true));
  $('#note-save').on('click', function () {
    const text = $('#note-text').val().trim();
    if (text && pendingNotePos) DrawTool.addNote(pendingNotePos, text);
    $('#note-popup').prop('hidden', true);
  });

  // ---- Text label popup ----
  // Direct floorplan edit (like the eraser) rather than a Claude annotation — clicking an
  // existing label edits/deletes it in place, saved via the same save_edit.php flow.
  function requestTextLabel(pos, evt) {
    if (!activeVersion || !activeVersion.floorplan) return;
    const hit = findTextLabelHit(activeVersion.floorplan, pos);
    const px = evt.clientX, py = evt.clientY;
    if (hit) {
      pendingTextLabel = { mode: 'edit', existing: hit };
      $('#text-label-text').val(hit.text || '');
      $('#text-label-delete').prop('hidden', false);
    } else {
      pendingTextLabel = { mode: 'add', pos };
      $('#text-label-text').val('');
      $('#text-label-delete').prop('hidden', true);
    }
    $('#text-label-popup').css({ left: px + 'px', top: py + 'px' }).prop('hidden', false);
    $('#text-label-text').trigger('focus');
  }

  function applyTextLabelEdit(fp) {
    renderFloorplan(svg, fp);
    hasPendingEdits = true;
    $('#btn-save-edits').prop('hidden', false);
    $('#text-label-popup').prop('hidden', true);
    pendingTextLabel = null;
  }

  $('#text-label-cancel').on('click', () => { $('#text-label-popup').prop('hidden', true); pendingTextLabel = null; });
  $('#text-label-save').on('click', function () {
    if (!pendingTextLabel || !activeVersion || !activeVersion.floorplan) { $('#text-label-popup').prop('hidden', true); return; }
    const fp = activeVersion.floorplan;
    const text = $('#text-label-text').val().trim();
    if (pendingTextLabel.mode === 'edit') {
      if (text) {
        pendingTextLabel.existing.text = text;
      } else {
        fp.text_labels = (fp.text_labels || []).filter(l => l !== pendingTextLabel.existing);
      }
    } else if (text) {
      fp.text_labels = fp.text_labels || [];
      fp.text_labels.push({ id: 't' + (++textLabelCounter), x: pendingTextLabel.pos.x, y: pendingTextLabel.pos.y, text });
    }
    applyTextLabelEdit(fp);
  });
  $('#text-label-delete').on('click', function () {
    if (!pendingTextLabel || pendingTextLabel.mode !== 'edit' || !activeVersion || !activeVersion.floorplan) {
      $('#text-label-popup').prop('hidden', true);
      return;
    }
    const fp = activeVersion.floorplan;
    fp.text_labels = (fp.text_labels || []).filter(l => l !== pendingTextLabel.existing);
    applyTextLabelEdit(fp);
  });

  window.addEventListener('resize', () => { fitStage(currentCanvasW, currentCanvasH); DrawTool.resize(); });

  DrawTool.init(canvas, 1000, 700, requestNote, eraseAt, requestTextLabel);
  loadProject();
});
