<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/navbar.php';
require_once '../includes/top_navbar.php';
require_once '../includes/db.php';
$rooms = $pdo->query("SELECT id, name FROM rooms ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<title>Карта сети</title>
<link href="/adminis/includes/style.css" rel="stylesheet">
<style>
.map-page-wrap { display:flex; flex-direction:column; gap:10px; height:calc(100vh - 98px); }

/* ── Toolbar ── */
.map-toolbar {
    background:#fff; border:1px solid #e5e7ef; border-radius:10px;
    padding:8px 16px; display:flex; align-items:center; gap:10px;
    flex-wrap:wrap; box-shadow:0 1px 4px rgba(0,0,0,.04); flex-shrink:0;
}
.map-sep { width:1px; height:22px; background:#e5e7ef; flex-shrink:0; }
.map-lbl { font-size:11px; font-weight:600; color:#9ca3c4; text-transform:uppercase; letter-spacing:.5px; white-space:nowrap; }
.map-toolbar .form-select { font-size:13px; padding:4px 8px; min-width:120px; }
.tb-group { display:flex; align-items:center; gap:8px; }

/* Слайдер расстояния */
.dist-slider { width:90px; accent-color:#4f6ef7; cursor:pointer; }
.dist-val { font-size:12px; font-weight:600; color:#4f6ef7; min-width:24px; text-align:right; }

/* ── Canvas wrap ── */
#map-wrap { position:relative; flex:1; min-height:0; }
#mapCanvas {
    width:100%; height:100%; display:block;
    border:1px solid #e5e7ef; border-radius:10px;
    background:#f8f9fd;
}
/* ── Tooltip ── */
#tooltip {
    position:fixed; background:#1e2130; color:#e2e8ff;
    border-radius:8px; padding:8px 12px; font-size:12px; line-height:1.6;
    pointer-events:none; z-index:9999; display:none; max-width:220px;
    box-shadow:0 4px 16px rgba(0,0,0,.3);
}
/* ── Legend ── */
#legend {
    position:absolute; bottom:16px; right:16px; background:#ffffffee;
    border:1px solid #e5e7ef; border-radius:8px; padding:10px 14px;
    font-size:11px; color:#6b7499; display:flex; flex-direction:column;
    gap:5px; box-shadow:0 2px 8px rgba(0,0,0,.06); pointer-events:none;
}
.leg-row { display:flex; align-items:center; gap:7px; }
.leg-dot { width:10px; height:10px; border-radius:3px; flex-shrink:0; }

/* ── Controls hint ── */
#controls-hint {
    position:absolute; bottom:16px; left:16px; background:#ffffffee;
    border:1px solid #e5e7ef; border-radius:8px; padding:9px 13px;
    font-size:11px; color:#6b7499; display:flex; flex-direction:column;
    gap:4px; box-shadow:0 2px 8px rgba(0,0,0,.06); pointer-events:none;
    line-height:1.5;
}
.hint-row { display:flex; align-items:center; gap:7px; }
.hint-key {
    background:#f1f5f9; border:1px solid #d1d9f0; border-radius:4px;
    padding:1px 5px; font-size:10px; font-weight:600; color:#4f6ef7;
    white-space:nowrap; flex-shrink:0;
}

/* ── Selection box ── */
#selBox {
    position:absolute; border:1.5px dashed #4f6ef7;
    background:rgba(79,110,247,.07); pointer-events:none;
    display:none; border-radius:3px;
}
</style>
</head>
<body>
<div class="content-wrapper" style="padding:16px 24px">
<div class="map-page-wrap">

    <!-- ── Toolbar ── -->
    <div class="map-toolbar">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#4f6ef7" stroke-width="2">
            <polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6"/>
            <line x1="8" y1="2" x2="8" y2="18"/><line x1="16" y1="6" x2="16" y2="22"/>
        </svg>
        <span style="font-size:15px;font-weight:700;color:#1e2130">Карта сети</span>

        <div class="map-sep"></div>

        <div class="tb-group">
            <span class="map-lbl">Кабинет</span>
            <select id="filterRoom" class="form-select">
                <option value="">Все</option>
                <?php foreach ($rooms as $r): ?>
                    <option value="<?= $r['id'] ?>"><?= htmlspecialchars($r['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="map-sep"></div>

        <div class="tb-group">
            <span class="map-lbl">Тип</span>
            <select id="filterType" class="form-select">
                <option value="">Все типы</option>
                <option value="Свитч">Свитчи</option>
                <option value="Маршрутизатор">Маршрутизаторы</option>
                <option value="Сервер">Серверы</option>
                <option value="ПК">Компьютеры</option>
                <option value="Принтер">Принтеры</option>
                <option value="ИБП">ИБП</option>
            </select>
        </div>

        <div class="map-sep"></div>

        <!-- Расстояние между узлами -->
        <div class="tb-group">
            <span class="map-lbl">Расстояние</span>
            <input type="range" id="distSlider" class="dist-slider" min="20" max="200" value="55" step="5">
            <span class="dist-val" id="distVal">55</span>
        </div>

        <div class="map-sep"></div>

        <div class="tb-group" style="margin-left:auto">
            <button class="btn btn-outline-success" onclick="fitView();draw()">⊡ По размеру</button>
            <button class="btn btn-outline-success" onclick="reLayout()">↺ Пересчитать</button>
            <button class="btn btn-outline-success" onclick="downloadPDF()">⬇ PDF</button>
        </div>
    </div>

    <!-- ── Canvas ── -->
    <div id="map-wrap">
        <canvas id="mapCanvas"></canvas>
        <div id="selBox"></div>

        <!-- Легенда -->
        <div id="legend">
            <div class="leg-row"><div class="leg-dot" style="background:#eff3ff;border:2px solid #4f6ef7"></div>Свитч</div>
            <div class="leg-row"><div class="leg-dot" style="background:#f0fdf4;border:2px solid #16a34a"></div>Маршрутизатор</div>
            <div class="leg-row"><div class="leg-dot" style="background:#fff;border:1px solid #d1d9f0"></div>Устройство</div>
            <div class="leg-row"><div class="leg-dot" style="background:#fff5f5;border:1px solid #fca5a5"></div>Проблемы ⚠</div>
        </div>

        <!-- Подсказки управления -->
        <div id="controls-hint">
            <div class="hint-row"><span class="hint-key">Колесо</span> панорама карты</div>
            <div class="hint-row"><span class="hint-key">Колесо 🖱</span> нажать и тянуть — панорама</div>
            <div class="hint-row"><span class="hint-key">Ctrl+Колесо</span> масштаб</div>
            <div class="hint-row"><span class="hint-key">ЛКМ по узлу</span> выбрать / перетащить</div>
            <div class="hint-row"><span class="hint-key">ЛКМ drag</span> рамка мультивыбора</div>
            <div class="hint-row"><span class="hint-key">Ctrl+ЛКМ</span> добавить к выбору</div>
            <div class="hint-row"><span class="hint-key">2×ЛКМ</span> открыть устройство</div>
            <div class="hint-row"><span class="hint-key">Esc</span> снять выбор</div>
        </div>
    </div>
</div>
</div>
<div id="tooltip"></div>

<script>
// ─── Константы ────────────────────────────────────────────────────────────────
const NODE_W=112, NODE_H=78, ICON_SIZE=36;

const TYPE_STYLE={
    'Свитч':         {bg:'#eff3ff',border:'#4f6ef7',hub:true},
    'Маршрутизатор': {bg:'#f0fdf4',border:'#16a34a',hub:true},
    'Сервер':        {bg:'#fdf4ff',border:'#9333ea'},
    'ПК':            {bg:'#fff',   border:'#d1d9f0'},
    'Ноутбук':       {bg:'#fff',   border:'#d1d9f0'},
    'Принтер':       {bg:'#fff7ed',border:'#fed7aa'},
    'ИБП':           {bg:'#f0fdf4',border:'#bbf7d0'},
};

// ─── State ────────────────────────────────────────────────────────────────────
let allNodes=[], allEdges=[];
let nodes=[], edges=[];
let camX=0, camY=0, camScale=1;
let hoveredNode=null;
let animId=null, simDone=false;

// Мультивыбор
let selected=new Set();       // Set of node keys
let isDraggingNodes=false;
let dragStartW={x:0,y:0};     // world-coords at drag start
let dragStartPos=new Map();   // key → {x,y} snapshot

// Рамка выбора
let isBoxSelecting=false;
let boxStart={x:0,y:0};       // screen coords

// Панорама (колесо или средняя кнопка)
let isPanning=false;
let panStartX=0, panStartY=0;

const canvas=document.getElementById('mapCanvas');
const ctx=canvas.getContext('2d');
const wrap=document.getElementById('map-wrap');
const selBox=document.getElementById('selBox');

// ─── Resize ───────────────────────────────────────────────────────────────────
function resizeCanvas(){
    canvas.width=wrap.clientWidth||800;
    canvas.height=wrap.clientHeight||600;
    draw();
}
new ResizeObserver(()=>resizeCanvas()).observe(wrap);

// ─── Image cache ──────────────────────────────────────────────────────────────
const imgCache={};
function getImg(src){
    if(imgCache[src]) return imgCache[src];
    const img=new Image(); img.src=src;
    img.onload=()=>draw();
    return imgCache[src]=img;
}

// ─── Helpers ──────────────────────────────────────────────────────────────────
function nodeById(id){ return nodes.find(n=>n.key===id); }
function screenToWorld(sx,sy){ return {x:sx/camScale-camX, y:sy/camScale-camY}; }
function worldToScreen(wx,wy){ return {x:(wx+camX)*camScale, y:(wy+camY)*camScale}; }

function hitNode(wx,wy){
    for(let i=nodes.length-1;i>=0;i--){
        const n=nodes[i];
        if(Math.abs(wx-n.x)<NODE_W/2&&Math.abs(wy-n.y)<NODE_H/2) return n;
    }
    return null;
}

function fitView(){
    if(!nodes.length) return;
    let x0=Infinity,y0=Infinity,x1=-Infinity,y1=-Infinity;
    nodes.forEach(n=>{
        x0=Math.min(x0,n.x-NODE_W/2-14); y0=Math.min(y0,n.y-NODE_H/2-18);
        x1=Math.max(x1,n.x+NODE_W/2+4);  y1=Math.max(y1,n.y+NODE_H/2+4);
    });
    const pad=40,cw=x1-x0+pad*2,ch=y1-y0+pad*2;
    camScale=Math.min(canvas.width/cw,canvas.height/ch,2);
    camX=(-x0+pad)+(canvas.width/camScale-cw)/2;
    camY=(-y0+pad)+(canvas.height/camScale-ch)/2;
}

// ─── Force sim ────────────────────────────────────────────────────────────────
function getIdeal(){ return parseInt(document.getElementById('distSlider').value)||55; }

function initPositions(){
    const hubs=nodes.filter(n=>TYPE_STYLE[n.type]?.hub);
    const rest=nodes.filter(n=>!TYPE_STYLE[n.type]?.hub);
    const R=100;
    hubs.forEach((n,i)=>{
        const a=(2*Math.PI*i)/Math.max(1,hubs.length);
        n.x=Math.cos(a)*R*0.2+(Math.random()-.5)*30;
        n.y=Math.sin(a)*R*0.2+(Math.random()-.5)*30;
        n.vx=0;n.vy=0;
    });
    rest.forEach((n,i)=>{
        const a=(2*Math.PI*i)/Math.max(1,rest.length);
        n.x=Math.cos(a)*R+(Math.random()-.5)*50;
        n.y=Math.sin(a)*R+(Math.random()-.5)*50;
        n.vx=0;n.vy=0;
    });
}

function runSim(totalSteps){
    if(animId) cancelAnimationFrame(animId);
    simDone=false;
    let step=0;
    const REPEL=600, DAMP=0.72, GRAVITY=0.008;

    function tick(){
        if(step++>=totalSteps){ fitView(); simDone=true; draw(); return; }

        const IDEAL=getIdeal();
        const ATTRACT=0.14;

        for(let i=0;i<nodes.length;i++){
            for(let j=i+1;j<nodes.length;j++){
                const a=nodes[i],b=nodes[j];
                const dx=a.x-b.x,dy=a.y-b.y;
                const d2=dx*dx+dy*dy+0.5;
                const f=REPEL/d2;
                a.vx+=dx*f; a.vy+=dy*f;
                b.vx-=dx*f; b.vy-=dy*f;
            }
        }
        edges.forEach(e=>{
            const a=nodeById(e.from),b=nodeById(e.to);
            if(!a||!b) return;
            const dx=b.x-a.x,dy=b.y-a.y;
            const d=Math.sqrt(dx*dx+dy*dy)||1;
            const f=(d-IDEAL)*ATTRACT;
            a.vx+=dx/d*f; a.vy+=dy/d*f;
            b.vx-=dx/d*f; b.vy-=dy/d*f;
        });
        nodes.forEach(n=>{
            if(selected.has(n.key)&&isDraggingNodes){n.vx=0;n.vy=0;return;}
            n.vx-=n.x*GRAVITY; n.vy-=n.y*GRAVITY;
            n.vx*=DAMP; n.vy*=DAMP;
            n.x+=n.vx; n.y+=n.vy;
        });

        if(step%10===0){
            draw();
            ctx.save();
            ctx.fillStyle='rgba(248,249,253,.75)';
            ctx.fillRect(0,0,canvas.width,canvas.height);
            ctx.fillStyle='#4f6ef7';
            ctx.font='bold 13px Inter,sans-serif';
            ctx.textAlign='center';
            ctx.fillText(`Расчёт раскладки… ${Math.round(step/totalSteps*100)}%`,canvas.width/2,canvas.height/2);
            ctx.restore();
        }
        animId=requestAnimationFrame(tick);
    }
    tick();
}

function reLayout(){
    initPositions();
    runSim(220);
}

// ─── wrapText ─────────────────────────────────────────────────────────────────
function wrapText(text,maxW){
    if(ctx.measureText(text).width<=maxW) return [text];
    const words=text.split(' ');
    if(words.length>1){
        for(let i=words.length-1;i>=1;i--){
            const l1=words.slice(0,i).join(' ');
            if(ctx.measureText(l1).width<=maxW){
                let l2=words.slice(i).join(' ');
                while(ctx.measureText(l2).width>maxW&&l2.length>3) l2=l2.slice(0,-1);
                if(l2!==words.slice(i).join(' ')) l2+='…';
                return [l1,l2];
            }
        }
    }
    let l=text;
    while(ctx.measureText(l).width>maxW&&l.length>3) l=l.slice(0,-1);
    return [l+(l!==text?'…':'')];
}

// ─── Draw ─────────────────────────────────────────────────────────────────────
function rr(x,y,w,h,r){
    ctx.beginPath();
    ctx.moveTo(x+r,y); ctx.lineTo(x+w-r,y); ctx.quadraticCurveTo(x+w,y,x+w,y+r);
    ctx.lineTo(x+w,y+h-r); ctx.quadraticCurveTo(x+w,y+h,x+w-r,y+h);
    ctx.lineTo(x+r,y+h); ctx.quadraticCurveTo(x,y+h,x,y+h-r);
    ctx.lineTo(x,y+r); ctx.quadraticCurveTo(x,y,x+r,y);
    ctx.closePath();
}

function draw(){
    ctx.clearRect(0,0,canvas.width,canvas.height);
    ctx.save();
    ctx.scale(camScale,camScale);
    ctx.translate(camX,camY);

    // Рёбра
    edges.forEach(e=>{
        const a=nodeById(e.from),b=nodeById(e.to);
        if(!a||!b) return;
        const isHub=TYPE_STYLE[a.type]?.hub||TYPE_STYLE[b.type]?.hub;
        ctx.beginPath();
        ctx.moveTo(a.x,a.y); ctx.lineTo(b.x,b.y);
        ctx.strokeStyle=isHub?'rgba(79,110,247,.5)':'rgba(148,163,184,.38)';
        ctx.lineWidth=(isHub?1.8:1.1)/camScale;
        ctx.stroke();
    });

    // Узлы
    nodes.forEach(n=>{
        const ts=TYPE_STYLE[n.type]||{bg:'#fff',border:'#d1d9f0'};
        const isSel=selected.has(n.key);
        const isHov=n===hoveredNode;
        const x=n.x-NODE_W/2, y=n.y-NODE_H/2;

        ctx.shadowColor=isSel?'rgba(79,110,247,.45)':(isHov?'rgba(79,110,247,.3)':(ts.hub?'rgba(79,110,247,.1)':'transparent'));
        ctx.shadowBlur=isSel?16:(isHov?12:(ts.hub?6:0));

        rr(x,y,NODE_W,NODE_H,9);
        ctx.fillStyle=n.hasIssues?'#fff5f5':ts.bg;
        ctx.fill();
        ctx.strokeStyle=isSel?'#4f6ef7':(n.hasIssues?'#fca5a5':(isHov?'#818cf8':ts.border));
        ctx.lineWidth=(isSel||isHov||ts.hub?2:1)/camScale;
        ctx.stroke();
        ctx.shadowBlur=0; ctx.shadowColor='transparent';

        // Иконка
        if(n.icon){
            const img=getImg(n.icon);
            if(img.complete&&img.naturalWidth){
                const iy=y+(NODE_H-ICON_SIZE-28)/2;
                ctx.drawImage(img,n.x-ICON_SIZE/2,iy,ICON_SIZE,ICON_SIZE);
            }
        }

        // Название
        ctx.fillStyle='#2d3560'; ctx.font='11.5px Inter,sans-serif'; ctx.textAlign='center';
        const lines=wrapText(n.text||'',NODE_W-14);
        const lh=13.5;
        const ty=y+NODE_H-(lines.length>1?lh+5:9);
        lines.forEach((ln,li)=>ctx.fillText(ln,n.x,ty+li*lh));

        // Кабинет над узлом
        if(n.room_name){
            ctx.fillStyle='#9ca3c4'; ctx.font='bold 9.5px Inter,sans-serif'; ctx.textAlign='center';
            let rm=n.room_name;
            while(ctx.measureText(rm).width>NODE_W-6&&rm.length>3) rm=rm.slice(0,-1);
            if(rm!==n.room_name) rm+='…';
            ctx.fillText(rm,n.x,y-4);
        }

        // Значок проблемы
        if(n.hasIssues){
            ctx.fillStyle='#dc2626'; ctx.font='bold 10px Inter,sans-serif'; ctx.textAlign='right';
            ctx.fillText('⚠',x+NODE_W-3,y+12);
        }

        // Галочка выбора
        if(isSel){
            ctx.fillStyle='#4f6ef7';
            ctx.beginPath();
            ctx.arc(x+NODE_W-7,y+7,5,0,Math.PI*2);
            ctx.fill();
            ctx.fillStyle='#fff'; ctx.font='bold 7px Inter,sans-serif'; ctx.textAlign='center';
            ctx.fillText('✓',x+NODE_W-7,y+10);
        }
    });

    ctx.restore();
}

// ─── Events ───────────────────────────────────────────────────────────────────

// Колесо = панорама, Ctrl+колесо = зум
canvas.addEventListener('wheel',e=>{
    e.preventDefault();
    if(e.ctrlKey||e.metaKey){
        // Зум к курсору
        const rect=canvas.getBoundingClientRect();
        const w=screenToWorld(e.clientX-rect.left,e.clientY-rect.top);
        camScale=Math.max(0.07,Math.min(5,camScale*(e.deltaY>0?.9:1.1)));
        camX=(e.clientX-rect.left)/camScale-w.x;
        camY=(e.clientY-rect.top)/camScale-w.y;
    } else {
        // Панорама
        const f=1/camScale;
        camX-=e.deltaX*f;
        camY-=e.deltaY*f;
    }
    draw();
},{passive:false});

// Средняя кнопка мыши — панорама (как в браузере)
canvas.addEventListener('mousedown',e=>{
    if(e.button===1){
        e.preventDefault();
        isPanning=true;
        panStartX=e.clientX-camX*camScale;
        panStartY=e.clientY-camY*camScale;
        canvas.style.cursor='grabbing';
        return;
    }
    if(e.button!==0) return;
    const rect=canvas.getBoundingClientRect();
    const sx=e.clientX-rect.left, sy=e.clientY-rect.top;
    const w=screenToWorld(sx,sy);
    const hit=hitNode(w.x,w.y);

    if(hit){
        // Клик по узлу
        if(e.ctrlKey||e.metaKey){
            // Ctrl+клик — добавить/убрать из выбора
            if(selected.has(hit.key)) selected.delete(hit.key);
            else selected.add(hit.key);
        } else {
            if(!selected.has(hit.key)){
                selected.clear();
                selected.add(hit.key);
            }
        }
        // Начинаем drag выбранных
        isDraggingNodes=true;
        dragStartW={x:w.x,y:w.y};
        dragStartPos=new Map();
        selected.forEach(k=>{
            const n=nodeById(k);
            if(n) dragStartPos.set(k,{x:n.x,y:n.y});
        });
        canvas.style.cursor='grabbing';
        draw();
    } else {
        // Клик по пустому месту — начинаем рамку выбора
        if(!e.ctrlKey&&!e.metaKey) selected.clear();
        isBoxSelecting=true;
        boxStart={x:sx,y:sy};
        updateSelBox(sx,sy,sx,sy);
        selBox.style.display='block';
        draw();
    }
});

canvas.addEventListener('mousemove',e=>{
    const rect=canvas.getBoundingClientRect();
    const sx=e.clientX-rect.left, sy=e.clientY-rect.top;
    const w=screenToWorld(sx,sy);

    // Панорама средней кнопкой
    if(isPanning){
        camX=(e.clientX-panStartX)/camScale;
        camY=(e.clientY-panStartY)/camScale;
        draw(); return;
    }

    if(isDraggingNodes){
        const dx=w.x-dragStartW.x, dy=w.y-dragStartW.y;
        selected.forEach(k=>{
            const n=nodeById(k);
            const s=dragStartPos.get(k);
            if(n&&s){ n.x=s.x+dx; n.y=s.y+dy; n.vx=0; n.vy=0; }
        });
        draw(); return;
    }

    if(isBoxSelecting){
        updateSelBox(boxStart.x,boxStart.y,sx,sy);
        draw(); return;
    }

    // Hover
    const hit=hitNode(w.x,w.y);
    if(hit!==hoveredNode){ hoveredNode=hit; draw(); }
    canvas.style.cursor=hit?'pointer':'default';
    const tip=document.getElementById('tooltip');
    if(hit?.tooltip){ tip.style.display='block'; tip.style.left=(e.clientX+14)+'px'; tip.style.top=(e.clientY-10)+'px'; tip.innerHTML=hit.tooltip.replace(/\n/g,'<br>'); }
    else tip.style.display='none';
});

canvas.addEventListener('mouseup',e=>{
    if(e.button===1){ isPanning=false; canvas.style.cursor='default'; return; }
    if(e.button!==0) return;
    const rect=canvas.getBoundingClientRect();
    const sx=e.clientX-rect.left, sy=e.clientY-rect.top;

    if(isBoxSelecting){
        // Завершаем рамку — выбираем узлы внутри
        const x0=Math.min(boxStart.x,sx), y0=Math.min(boxStart.y,sy);
        const x1=Math.max(boxStart.x,sx), y1=Math.max(boxStart.y,sy);
        nodes.forEach(n=>{
            const s=worldToScreen(n.x,n.y);
            if(s.x>=x0&&s.x<=x1&&s.y>=y0&&s.y<=y1) selected.add(n.key);
        });
        isBoxSelecting=false;
        selBox.style.display='none';
        draw();
    }

    if(isDraggingNodes){
        isDraggingNodes=false;
        canvas.style.cursor='default';
    }
});

canvas.addEventListener('mouseleave',()=>{
    isDraggingNodes=false; isBoxSelecting=false; isPanning=false;
    selBox.style.display='none';
    hoveredNode=null;
    document.getElementById('tooltip').style.display='none';
    canvas.style.cursor='default';
    draw();
});

// Двойной клик — открыть устройство
let lastClick=0;
canvas.addEventListener('click',e=>{
    const now=Date.now();
    if(now-lastClick<350){
        const rect=canvas.getBoundingClientRect();
        const w=screenToWorld(e.clientX-rect.left,e.clientY-rect.top);
        const hit=hitNode(w.x,w.y);
        if(hit) window.location.href='../rooms/edit_device.php?id='+hit.key+'&from=map';
    }
    lastClick=now;
});

// Esc — снять выбор
document.addEventListener('keydown',e=>{
    if(e.key==='Escape'){ selected.clear(); draw(); }
});

// Рамка выбора (DOM-элемент)
function updateSelBox(x0,y0,x1,y1){
    const left=Math.min(x0,x1), top=Math.min(y0,y1);
    const w=Math.abs(x1-x0), h=Math.abs(y1-y0);
    const wr=wrap.getBoundingClientRect(), cr=canvas.getBoundingClientRect();
    const offX=cr.left-wr.left, offY=cr.top-wr.top;
    selBox.style.left=(left+offX)+'px'; selBox.style.top=(top+offY)+'px';
    selBox.style.width=w+'px'; selBox.style.height=h+'px';
}

// ─── Slider ───────────────────────────────────────────────────────────────────
const distSlider=document.getElementById('distSlider');
const distVal=document.getElementById('distVal');
distSlider.addEventListener('input',()=>{
    distVal.textContent=distSlider.value;
});
distSlider.addEventListener('change',()=>{
    // Пересчитываем с новым расстоянием
    nodes.forEach(n=>{n.vx=0;n.vy=0;});
    runSim(150);
});

// ─── Filter ───────────────────────────────────────────────────────────────────
function applyFilter(){
    const roomId=document.getElementById('filterRoom').value;
    const typeF=document.getElementById('filterType').value;
    const EXCLUDED=new Set(['Ноутбук']);

    let fn=allNodes.filter(n=>{
        if(EXCLUDED.has(n.type)) return false;
        if(roomId&&String(n.room_id)!==roomId) return false;
        if(typeF&&n.type!==typeF) return false;
        return true;
    });

    const allowed=new Set(fn.map(n=>n.key));
    const fe=allEdges.filter(e=>allowed.has(e.from)&&allowed.has(e.to));

    // Убираем одиночек (без единого ребра)
    const connected=new Set();
    fe.forEach(e=>{connected.add(e.from);connected.add(e.to);});
    // Если фильтр по кабинету/типу — одиночек оставляем (нет смысла скрывать)
    // Только при показе всей сети убираем изолированные узлы
    if(!roomId&&!typeF){
        fn=fn.filter(n=>connected.has(n.key));
    }

    selected.clear();
    nodes=fn.map(n=>({...n,vx:0,vy:0}));
    edges=fe.filter(e=>nodes.some(n=>n.key===e.from)&&nodes.some(n=>n.key===e.to)).map(e=>({...e}));

    initPositions();
    runSim(220);
}

document.getElementById('filterRoom').addEventListener('change',applyFilter);
document.getElementById('filterType').addEventListener('change',applyFilter);

// ─── Load ─────────────────────────────────────────────────────────────────────
fetch('map_data.php')
    .then(r=>r.json())
    .then(data=>{
        allNodes=data.nodes.filter(n=>!n.isGroup);
        allEdges=data.edges;
        resizeCanvas();
        applyFilter();
    })
    .catch(err=>{
        ctx.fillStyle='#dc2626'; ctx.font='14px sans-serif';
        ctx.fillText('Ошибка загрузки: '+err.message,20,40);
    });

// ─── PDF ──────────────────────────────────────────────────────────────────────
function downloadPDF(){
    if(!simDone){alert('Дождитесь окончания расчёта раскладки');return;}
    fitView(); draw();
    const pending=Object.values(imgCache).filter(i=>!i.complete);
    const go=()=>{
        const off=document.createElement('canvas');
        off.width=canvas.width*2; off.height=canvas.height*2;
        const oc=off.getContext('2d');
        oc.fillStyle='#f8f9fd'; oc.fillRect(0,0,off.width,off.height);
        oc.scale(2,2); oc.drawImage(canvas,0,0);
        off.toBlob(blob=>{
            const url=URL.createObjectURL(blob);
            const win=window.open('','_blank');
            win.document.write(`<!DOCTYPE html><html><head><title>Карта сети</title><style>*{margin:0;padding:0}body{display:flex;align-items:center;justify-content:center;min-height:100vh;background:#fff}img{max-width:100%}@media print{img{width:100%}}</style></head><body><img src="${url}" onload="window.print()"></body></html>`);
            win.document.close();
        },'image/png');
    };
    if(pending.length) Promise.all(pending.map(i=>new Promise(r=>{i.onload=r;i.onerror=r;}))).then(()=>{fitView();draw();go();});
    else go();
}
</script>
</body>
</html>