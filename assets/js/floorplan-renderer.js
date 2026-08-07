/**
 * Renders the structured floorplan JSON (see includes/functions.php floorplan_schema_description)
 * into the #floorplan-svg element. Coordinates are already in the 0-1000 x 0-700-ish canvas space
 * that Claude was instructed to use, so we just set the viewBox to match and draw directly.
 */
function renderFloorplan(svgEl, data) {
  while (svgEl.firstChild) svgEl.removeChild(svgEl.firstChild);
  if (!data || !data.canvas) return;

  const NS = 'http://www.w3.org/2000/svg';
  const w = data.canvas.width || 1000;
  const h = data.canvas.height || 700;
  svgEl.setAttribute('viewBox', `0 0 ${w} ${h}`);

  const el = (tag, attrs) => {
    const node = document.createElementNS(NS, tag);
    for (const k in attrs) node.setAttribute(k, attrs[k]);
    return node;
  };

  // Arrowhead marker used by the stairs "up"/"down" direction indicator
  const defs = el('defs', {});
  const marker = el('marker', {
    id: 'fp-arrowhead', viewBox: '0 0 10 10', refX: '8', refY: '5',
    markerWidth: '6', markerHeight: '6', orient: 'auto-start-reverse'
  });
  marker.appendChild(el('path', { d: 'M 0 0 L 10 5 L 0 10 z', class: 'fp-stairs-arrow-head' }));
  defs.appendChild(marker);
  svgEl.appendChild(defs);

  // Rooms (fill) drawn first, underneath walls
  (data.rooms || []).forEach(room => {
    if (!room.polygon || room.polygon.length < 3) return;
    const points = room.polygon.map(p => p.join(',')).join(' ');
    svgEl.appendChild(el('polygon', { points, class: 'fp-room' }));
    if (room.label) {
      const t = el('text', { x: room.label.x, y: room.label.y, class: 'fp-room-label' });
      t.textContent = room.name || '';
      svgEl.appendChild(t);
    }
  });

  // Walls
  (data.walls || []).forEach(wall => {
    svgEl.appendChild(el('line', {
      x1: wall.x1, y1: wall.y1, x2: wall.x2, y2: wall.y2,
      class: 'fp-wall', 'stroke-width': wall.thickness || 6, 'stroke-linecap': 'square'
    }));
  });

  // Windows: draw as a short thicker perpendicular-ish segment along the wall line direction
  (data.windows || []).forEach(win => {
    const half = (win.width || 40) / 2;
    let x1, y1, x2, y2;
    if (win.orientation === 'vertical') {
      x1 = win.x; y1 = win.y - half; x2 = win.x; y2 = win.y + half;
    } else {
      x1 = win.x - half; y1 = win.y; x2 = win.x + half; y2 = win.y;
    }
    svgEl.appendChild(el('line', { x1, y1, x2, y2, class: 'fp-window' }));
  });

  // Doors: draw a gap indicator plus a quarter-circle swing arc
  (data.doors || []).forEach(door => {
    const width = door.width || 30;
    const half = width / 2;
    if (door.orientation === 'vertical') {
      const path = `M ${door.x} ${door.y - half} A ${width} ${width} 0 0 1 ${door.x + width} ${door.y - half}`;
      svgEl.appendChild(el('path', { d: path, class: 'fp-door-arc' }));
    } else {
      const path = `M ${door.x - half} ${door.y} A ${width} ${width} 0 0 1 ${door.x - half} ${door.y - width}`;
      svgEl.appendChild(el('path', { d: path, class: 'fp-door-arc' }));
    }
  });

  // Stairs: bounding box + evenly spaced tread lines across the run + a centerline arrow
  // pointing toward the higher floor (the standard architectural stair symbol).
  (data.stairs || []).forEach(stair => {
    const x = stair.x || 0, y = stair.y || 0;
    const width = stair.width || 0, height = stair.height || 0;
    if (!width || !height) return;
    svgEl.appendChild(el('rect', { x, y, width, height, class: 'fp-stairs' }));

    // A landing (steps: 0) between flights of a turning staircase is a bare pad —
    // no tread lines, no direction arrow.
    if (!stair.steps) return;

    const vertical = stair.orientation === 'vertical';
    const steps = Math.max(2, stair.steps);
    const up = stair.direction !== 'down';

    const runLength = vertical ? height : width;
    for (let i = 1; i < steps; i++) {
      const pos = (i / steps) * runLength;
      const line = vertical
        ? { x1: x, y1: y + pos, x2: x + width, y2: y + pos }
        : { x1: x + pos, y1: y, x2: x + pos, y2: y + height };
      svgEl.appendChild(el('line', { ...line, class: 'fp-stairs-tread' }));
    }

    const midCross = vertical ? x + width / 2 : y + height / 2;
    const start = up ? runLength * 0.15 : runLength * 0.85;
    const end = up ? runLength * 0.85 : runLength * 0.15;
    const arrowLine = vertical
      ? { x1: midCross, y1: y + start, x2: midCross, y2: y + end }
      : { x1: x + start, y1: midCross, x2: x + end, y2: midCross };
    svgEl.appendChild(el('line', { ...arrowLine, class: 'fp-stairs-arrow', 'marker-end': 'url(#fp-arrowhead)' }));
  });

  // Dimension lines
  (data.dimensions || []).forEach(dim => {
    if (!dim.from || !dim.to) return;
    svgEl.appendChild(el('line', { x1: dim.from[0], y1: dim.from[1], x2: dim.to[0], y2: dim.to[1], class: 'fp-dim-line' }));
    const mx = (dim.from[0] + dim.to[0]) / 2;
    const my = (dim.from[1] + dim.to[1]) / 2 - 6;
    const t = el('text', { x: mx, y: my, class: 'fp-dim-label' });
    t.textContent = dim.label || '';
    svgEl.appendChild(t);
  });

  // Notes (e.g. assumptions Claude made) are shown via the "Notes" button/modal
  // in editor.js instead of being drawn on the floorplan itself.
}

function serializeSvgForDownload(svgEl) {
  const clone = svgEl.cloneNode(true);
  clone.setAttribute('xmlns', 'http://www.w3.org/2000/svg');
  // Inline the styles we use so the standalone SVG file looks right outside the app
  const style = document.createElementNS('http://www.w3.org/2000/svg', 'style');
  style.textContent = `
    .fp-wall{stroke:#1d2b3a}
    .fp-room{fill:#dbe6ee;fill-opacity:.45;stroke:none}
    .fp-room-label{font-family:monospace;font-size:14px;fill:#1d2b3a;text-anchor:middle}
    .fp-door-arc{fill:none;stroke:#2f5a7c;stroke-width:1.4;stroke-dasharray:3 2}
    .fp-window{stroke:#2f5a7c;stroke-width:3}
    .fp-dim-line{stroke:#4a5a68;stroke-width:.7}
    .fp-dim-label{font-family:monospace;font-size:10px;fill:#4a5a68;text-anchor:middle}
    .fp-stairs{fill:none;stroke:#1d2b3a;stroke-width:1}
    .fp-stairs-tread{stroke:#1d2b3a;stroke-width:1}
    .fp-stairs-arrow{stroke:#1d2b3a;stroke-width:1.4}
    .fp-stairs-arrow-head{fill:#1d2b3a}
  `;
  clone.insertBefore(style, clone.firstChild);
  return new XMLSerializer().serializeToString(clone);
}
