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
  let currentStroke = null;
  let marks = []; // { type: 'stroke', points: [{x,y}], color } | { type: 'note', x, y, text }
  let canvasDataW = 1000, canvasDataH = 700;
  let onNoteRequested = null;

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

  function handleDown(evt) {
    if (tool === 'pen') {
      drawing = true;
      currentStroke = { type: 'stroke', color, points: [toDataCoords(evt)] };
    } else if (tool === 'note') {
      const pos = toDataCoords(evt);
      if (onNoteRequested) onNoteRequested(pos, evt);
    }
  }

  function handleMove(evt) {
    if (!drawing || tool !== 'pen') return;
    currentStroke.points.push(toDataCoords(evt));
    redraw();
    drawStrokeInProgress();
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
    currentStroke = null;
    redraw();
  }

  return {
    init(canvasEl, dataW, dataH, noteCallback) {
      canvas = canvasEl;
      ctx = canvas.getContext('2d');
      canvasDataW = dataW; canvasDataH = dataH;
      onNoteRequested = noteCallback;

      canvas.addEventListener('mousedown', handleDown);
      canvas.addEventListener('mousemove', handleMove);
      window.addEventListener('mouseup', handleUp);

      canvas.addEventListener('touchstart', e => { handleDown(e.touches[0]); e.preventDefault(); }, { passive: false });
      canvas.addEventListener('touchmove', e => { handleMove(e.touches[0]); e.preventDefault(); }, { passive: false });
      canvas.addEventListener('touchend', handleUp);

      window.addEventListener('resize', resizeCanvas);
      resizeCanvas();
    },
    setTool(t) { tool = t; },
    setColor(c) { color = c; },
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
