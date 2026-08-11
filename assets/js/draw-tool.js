/**
 * A small annotation layer on top of the floorplan SVG.
 * Coordinates are stored in the SAME 0-1000 x 0-700 canvas space as the floorplan JSON,
 * by converting from pixel coordinates using the canvas's actual rendered size.
 * That way the annotation summary sent to Claude lines up with the floorplan geometry.
 */
const DrawTool = (function () {
  let canvas, ctx;
  let tool = 'pen';
  let color = '#e85d2f';
  let drawing = false;
  let erasing = false;
  let currentStroke = null;
  let marks = []; // { type: 'stroke', points: [{x,y}], color } | { type: 'note', x, y, text }
  let canvasDataW = 1000, canvasDataH = 700;
  let onNoteRequested = null;
  let onErase = null;
  let onTextRequested = null;
  let draggingMarkIndex = -1;
  let dragLastPos = null;

  function toDataCoords(evt) {
    const rect = canvas.getBoundingClientRect();
    const px = (evt.clientX - rect.left) / rect.width;
    const py = (evt.clientY - rect.top) / rect.height;
    return { x: Math.round(px * canvasDataW), y: Math.round(py * canvasDataH) };
  }

  function resizeCanvas() {
    const rect = canvas.getBoundingClientRect();
    canvas.width = rect.width * window.devicePixelRatio;
    canvas.height = rect.height * window.devicePixelRatio;
    ctx.setTransform(window.devicePixelRatio, 0, 0, window.devicePixelRatio, 0, 0);
    redraw();
  }

  function dataToPixel(pt) {
    const rect = canvas.getBoundingClientRect();
    return { x: (pt.x / canvasDataW) * rect.width, y: (pt.y / canvasDataH) * rect.height };
  }

  function redraw() {
    const rect = canvas.getBoundingClientRect();
    ctx.clearRect(0, 0, rect.width, rect.height);
    marks.forEach(mark => {
      if (mark.type === 'stroke') {
        ctx.strokeStyle = mark.color;
        ctx.lineWidth = 3;
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';
        ctx.beginPath();
        mark.points.forEach((p, i) => {
          const px = dataToPixel(p);
          if (i === 0) ctx.moveTo(px.x, px.y); else ctx.lineTo(px.x, px.y);
        });
        ctx.stroke();
      } else if (mark.type === 'note') {
        const px = dataToPixel({ x: mark.x, y: mark.y });
        ctx.fillStyle = '#e85d2f';
        ctx.beginPath();
        ctx.arc(px.x, px.y, 5, 0, Math.PI * 2);
        ctx.fill();
        ctx.fillStyle = '#1d2b3a';
        ctx.font = '12px sans-serif';
        ctx.fillText(mark.text.slice(0, 28) + (mark.text.length > 28 ? '…' : ''), px.x + 8, px.y + 4);
      }
    });
  }

  // Distance from a point to a line segment, for hit-testing drawn strokes.
  function distToSegment(px, py, x1, y1, x2, y2) {
    const dx = x2 - x1, dy = y2 - y1;
    const lenSq = dx * dx + dy * dy;
    let t = lenSq === 0 ? 0 : ((px - x1) * dx + (py - y1) * dy) / lenSq;
    t = Math.max(0, Math.min(1, t));
    return Math.hypot(px - (x1 + t * dx), py - (y1 + t * dy));
  }

  // Finds the topmost (most recently drawn) stroke passing near pt, within tolerance.
  function findStrokeAt(pt) {
    const TOL = 8;
    for (let i = marks.length - 1; i >= 0; i--) {
      const m = marks[i];
      if (m.type !== 'stroke') continue;
      for (let j = 0; j < m.points.length - 1; j++) {
        const a = m.points[j], b = m.points[j + 1];
        if (distToSegment(pt.x, pt.y, a.x, a.y, b.x, b.y) <= TOL) return i;
      }
    }
    return -1;
  }

  function handleDown(evt) {
    if (tool === 'pen') {
      drawing = true;
      currentStroke = { type: 'stroke', color, points: [toDataCoords(evt)] };
    } else if (tool === 'note') {
      const pos = toDataCoords(evt);
      if (onNoteRequested) onNoteRequested(pos, evt);
    } else if (tool === 'eraser') {
      erasing = true;
      if (onErase) onErase(toDataCoords(evt));
    } else if (tool === 'text') {
      const pos = toDataCoords(evt);
      if (onTextRequested) onTextRequested(pos, evt);
    } else if (tool === 'pan') {
      const pos = toDataCoords(evt);
      const idx = findStrokeAt(pos);
      if (idx !== -1) {
        draggingMarkIndex = idx;
        dragLastPos = pos;
      }
    }
  }

  function handleMove(evt) {
    if (drawing && tool === 'pen') {
      currentStroke.points.push(toDataCoords(evt));
      redraw();
      drawStrokeInProgress();
    } else if (erasing && tool === 'eraser') {
      if (onErase) onErase(toDataCoords(evt));
    } else if (draggingMarkIndex !== -1 && tool === 'pan') {
      const pos = toDataCoords(evt);
      const dx = pos.x - dragLastPos.x, dy = pos.y - dragLastPos.y;
      const mark = marks[draggingMarkIndex];
      mark.points = mark.points.map(p => ({ x: p.x + dx, y: p.y + dy }));
      dragLastPos = pos;
      redraw();
    }
  }

  function drawStrokeInProgress() {
    if (!currentStroke) return;
    ctx.strokeStyle = currentStroke.color;
    ctx.lineWidth = 3;
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    ctx.beginPath();
    currentStroke.points.forEach((p, i) => {
      const px = dataToPixel(p);
      if (i === 0) ctx.moveTo(px.x, px.y); else ctx.lineTo(px.x, px.y);
    });
    ctx.stroke();
  }

  function handleUp() {
    if (drawing && currentStroke && currentStroke.points.length > 1) {
      marks.push(currentStroke);
    }
    drawing = false;
    erasing = false;
    currentStroke = null;
    draggingMarkIndex = -1;
    dragLastPos = null;
    redraw();
  }

  return {
    init(canvasEl, dataW, dataH, noteCallback, eraseCallback, textCallback) {
      canvas = canvasEl;
      ctx = canvas.getContext('2d');
      canvasDataW = dataW; canvasDataH = dataH;
      onNoteRequested = noteCallback;
      onErase = eraseCallback;
      onTextRequested = textCallback;

      canvas.addEventListener('mousedown', handleDown);
      canvas.addEventListener('mousemove', handleMove);
      window.addEventListener('mouseup', handleUp);

      canvas.addEventListener('touchstart', e => { handleDown(e.touches[0]); e.preventDefault(); }, { passive: false });
      canvas.addEventListener('touchmove', e => { handleMove(e.touches[0]); e.preventDefault(); }, { passive: false });
      canvas.addEventListener('touchend', handleUp);

      window.addEventListener('resize', resizeCanvas);
      resizeCanvas();
    },
    setTool(t) { tool = t; canvas.classList.toggle('tool-pan', t === 'pan'); },
    setColor(c) { color = c; },
    setCanvasSize(w, h) { canvasDataW = w || 1000; canvasDataH = h || 700; },
    addNote(pos, text) {
      marks.push({ type: 'note', x: pos.x, y: pos.y, text });
      redraw();
    },
    undo() { marks.pop(); redraw(); },
    clear() { marks = []; redraw(); },
    getMarks() { return marks; },
    hasMarks() { return marks.length > 0; },
    resize: resizeCanvas,
  };
})();
