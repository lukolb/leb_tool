let pdfLibPromise = null;
let fontkitPromise = null;

function loadScript(url, globalName){
  if (globalName && window[globalName]) return Promise.resolve(window[globalName]);
  return new Promise((resolve, reject) => {
    const existing = Array.from(document.scripts).find((script) => script.src === url);
    if (existing) {
      existing.addEventListener('load', () => resolve(globalName ? window[globalName] : true), {once:true});
      existing.addEventListener('error', () => reject(new Error(`Script konnte nicht geladen werden: ${url}`)), {once:true});
      return;
    }
    const script = document.createElement('script');
    script.src = url;
    script.onload = () => resolve(globalName ? window[globalName] : true);
    script.onerror = () => reject(new Error(`Script konnte nicht geladen werden: ${url}`));
    document.head.appendChild(script);
  });
}

function loadPdfLib(url){
  if (window.PDFLib) return Promise.resolve(window.PDFLib);
  if (!pdfLibPromise) pdfLibPromise = loadScript(url, 'PDFLib');
  return pdfLibPromise;
}

function loadFontkit(url){
  if (window.fontkit) return Promise.resolve(window.fontkit);
  if (!fontkitPromise) fontkitPromise = loadScript(url, 'fontkit');
  return fontkitPromise;
}

const monthNames = {
  de: {
    full: ['Januar','Februar','März','April','Mai','Juni','Juli','August','September','Oktober','November','Dezember'],
    short: ['Jan','Feb','Mär','Apr','Mai','Jun','Jul','Aug','Sep','Okt','Nov','Dez'],
  },
  en: {
    full: ['January','February','March','April','May','June','July','August','September','October','November','December'],
    short: ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'],
  },
};

function parseFlexibleDate(raw){
  const value = String(raw ?? '').trim();
  if (!value) return null;
  let match = value.match(/^(\d{4})-(\d{1,2})-(\d{1,2})$/);
  if (match) return {y:+match[1],m:+match[2],d:+match[3]};
  match = value.match(/^(\d{1,2})[.\-/](\d{1,2})[.\-/](\d{2,4})$/);
  if (!match) return null;
  let year = +match[3];
  if (year < 100) year += 2000;
  return {y:year,m:+match[2],d:+match[1]};
}

function formatDate(parts, format, language){
  if (!parts || !format) return '';
  const lang = monthNames[language] ? language : 'de';
  const tokens = {
    MMMM: monthNames[lang].full[parts.m - 1] || '',
    MMM: monthNames[lang].short[parts.m - 1] || '',
    YYYY: String(parts.y),
    YY: String(parts.y).slice(-2),
    DD: String(parts.d).padStart(2,'0'),
    D: String(parts.d),
    MM: String(parts.m).padStart(2,'0'),
    M: String(parts.m),
  };
  return format.replace(/(?<!\p{L})(MMMM|MMM|YYYY|YY|DD|MM|D|M)(?!\p{L})/gu, (token) => tokens[token] ?? token);
}

function normalizeDate(raw, format, language){
  const value = String(raw ?? '').trim();
  const pattern = String(format ?? '').trim();
  if (!value || !pattern) return value;
  const parsed = parseFlexibleDate(value);
  return parsed ? (formatDate(parsed, pattern, language) || value) : value;
}

function normalizeFontName(raw){
  if (!raw) return '';
  return String(raw).trim().replace(/^\//,'').replace(/^[A-Z]{6}\+/,'').replace(/\s+/g,' ')
    .toLowerCase().replace(/[^a-z0-9._-]+/g,'_').replace(/^[_\-.]+|[_\-.]+$/g,'');
}

function fontKeys(base){
  if (!base) return [];
  return Array.from(new Set([base,base.replace(/-/g,'_'),base.replace(/_/g,'-')]));
}

function pdfNameToString(value){
  if (!value) return '';
  if (typeof value === 'string') return value.replace(/^\//,'');
  if (typeof value.decodeText === 'function') return value.decodeText().replace(/^\//,'');
  if (typeof value.asString === 'function') return value.asString().replace(/^\//,'');
  if (typeof value.key === 'string') return value.key.replace(/^\//,'');
  return String(value).replace(/^\//,'');
}

function pdfText(value){
  if (!value) return '';
  if (typeof value.decodeText === 'function') return value.decodeText();
  if (typeof value.asString === 'function') return value.asString();
  if (typeof value.value === 'string') return value.value;
  return String(value);
}

function defaultAppearance(field, PDFName){
  try { return pdfText(field?.acroField?.dict?.lookup?.(PDFName.of('DA'))); } catch { return ''; }
}

function appearanceFontKey(appearance){
  const match = /\/([^\s]+)\s+[\d.]+\s+Tf/.exec(appearance || '');
  return match ? match[1] : '';
}

function baseFontName(field, key, PDFName, form){
  if (!key) return '';
  try {
    const resources = field?.acroField?.dict?.lookup?.(PDFName.of('DR')) || form?.acroForm?.dict?.lookup?.(PDFName.of('DR'));
    const fonts = resources?.lookup?.(PDFName.of('Font'));
    const font = fonts?.lookup?.(PDFName.of(key));
    const base = font?.lookup?.(PDFName.of('BaseFont')) || font?.dict?.lookup?.(PDFName.of('BaseFont'));
    if (base) return pdfNameToString(base);
  } catch {}
  return key;
}

async function loadFontManifest(url){
  if (!url) return new Map();
  const response = await fetch(url,{credentials:'same-origin'});
  if (!response.ok) return new Map();
  const data = await response.json();
  const manifest = new Map();
  (data.fonts || []).forEach((entry) => {
    const key = normalizeFontName(entry.name || entry.key || '');
    fontKeys(key).forEach((candidate) => manifest.set(candidate,entry));
  });
  return manifest;
}

function standardFonts(PDFLib){
  const fonts = PDFLib?.StandardFonts || {};
  return {
    helvetica: fonts.Helvetica,
    'helvetica-bold': fonts.HelveticaBold,
    'helvetica-oblique': fonts.HelveticaOblique,
    'helvetica-boldoblique': fonts.HelveticaBoldOblique,
    'times-roman': fonts.TimesRoman,
    'times-bold': fonts.TimesBold,
    'times-italic': fonts.TimesItalic,
    'times-bolditalic': fonts.TimesBoldItalic,
    courier: fonts.Courier,
    'courier-bold': fonts.CourierBold,
    'courier-oblique': fonts.CourierOblique,
    'courier-boldoblique': fonts.CourierBoldOblique,
    symbol: fonts.Symbol,
    zapfdingbats: fonts.ZapfDingbats,
  };
}

async function embeddedFont(pdfDoc, rawName, manifest, cache, options, PDFLib){
  const keys = fontKeys(normalizeFontName(rawName));
  for (const key of keys) if (cache.has(key)) return cache.get(key);
  const builtIns = standardFonts(PDFLib);
  for (const key of keys) {
    if (!builtIns[key]) continue;
    const font = await pdfDoc.embedFont(builtIns[key]);
    cache.set(key,font);
    return font;
  }
  for (const key of keys) {
    const custom = manifest.get(key);
    if (!custom?.url) continue;
    await loadFontkit(options.fontkitUrl);
    if (window.fontkit && typeof pdfDoc.registerFontkit === 'function') pdfDoc.registerFontkit(window.fontkit);
    const response = await fetch(custom.url,{credentials:'same-origin'});
    if (!response.ok) continue;
    const font = await pdfDoc.embedFont(await response.arrayBuffer());
    cache.set(key,font);
    return font;
  }
  return null;
}

async function updateTextAppearances(form, pdfDoc, options, PDFLib){
  const {PDFName,PDFTextField,PDFDropdown,PDFOptionList} = PDFLib;
  const manifest = await loadFontManifest(options.fontManifestUrl);
  const cache = new Map();
  let fallback = null;
  try { fallback = await pdfDoc.embedFont(PDFLib.StandardFonts.Helvetica); } catch {}
  for (const field of form.getFields()) {
    const supported = (PDFTextField && field instanceof PDFTextField)
      || (PDFDropdown && field instanceof PDFDropdown)
      || (PDFOptionList && field instanceof PDFOptionList);
    if (!supported) continue;
    const key = appearanceFontKey(defaultAppearance(field,PDFName));
    const base = baseFontName(field,key,PDFName,form);
    let font = await embeddedFont(pdfDoc,base,manifest,cache,options,PDFLib);
    if (!font && key) font = await embeddedFont(pdfDoc,key,manifest,cache,options,PDFLib);
    if (!font) font = fallback;
    try {
      if (font && typeof field.updateAppearances === 'function') field.updateAppearances(font);
      else field.updateAppearances?.();
    } catch {}
  }
}

function parseColor(appearance){
  const text = String(appearance || '');
  let match = text.match(/([\d.]+)\s+([\d.]+)\s+([\d.]+)\s+(?:RG|rg)\b/);
  if (match) return {model:'rgb',values:match.slice(1,4).map(Number)};
  match = text.match(/([\d.]+)\s+(?:G|g)\b/);
  if (match) return {model:'gray',values:[Number(match[1])]};
  match = text.match(/([\d.]+)\s+([\d.]+)\s+([\d.]+)\s+([\d.]+)\s+(?:K|k)\b/);
  if (match) return {model:'cmyk',values:match.slice(1,5).map(Number)};
  return null;
}

function hexColor(hex){
  const match = /^#([\da-fA-F]{6})$/.exec(String(hex || '').trim());
  if (!match) return null;
  const raw = match[1];
  return {model:'rgb',values:[parseInt(raw.slice(0,2),16)/255,parseInt(raw.slice(2,4),16)/255,parseInt(raw.slice(4,6),16)/255]};
}

function colorOperator(color){
  if (!color?.values) return '';
  if (color.model === 'rgb') return `${color.values.join(' ')} RG`;
  if (color.model === 'gray') return `${color.values[0]} G`;
  if (color.model === 'cmyk') return `${color.values.join(' ')} K`;
  return '';
}

function widgetTextColor(widget, field, PDFName){
  try {
    const appearance = widget?.dict?.lookup?.(PDFName.of('DA'));
    const color = parseColor(pdfText(appearance));
    if (color) return color;
  } catch {}
  try { return parseColor(pdfText(field?.acroField?.dict?.lookup?.(PDFName.of('DA')))); } catch { return null; }
}

function crossColor(widget, field, options, PDFName){
  if (options.radioCrossColorMode === 'admin') {
    let name = '';
    try { name = String(field.getName?.() || ''); } catch {}
    const configured = /[-_]S$/i.test(name) ? options.radioCrossColorStudentHex : options.radioCrossColorTeacherHex;
    return hexColor(configured) || {model:'rgb',values:[0,0,0]};
  }
  return widgetTextColor(widget,field,PDFName);
}

function appearanceStream(pdfDoc, rect, color, selected){
  const width = Math.max(1,rect.width || 1);
  const height = Math.max(1,rect.height || 1);
  let content = '';
  if (selected) {
    const min = Math.min(width,height);
    const inset = min*.18;
    const line = Math.min(2.5,Math.max(.8,min*.1));
    content = ['q',colorOperator(color),`${line} w`,`${inset} ${inset} m ${width-inset} ${height-inset} l`,`${inset} ${height-inset} m ${width-inset} ${inset} l`,'S','Q'].filter(Boolean).join('\n');
  }
  return pdfDoc.context.register(pdfDoc.context.flateStream(content,{Type:'XObject',Subtype:'Form',BBox:[0,0,width,height],Resources:{}}));
}

function widgetOnName(widget, PDFName){
  try {
    const normal = widget?.dict?.lookup?.(PDFName.of('AP'))?.lookup?.(PDFName.of('N'));
    for (const key of normal?.keys?.() || []) {
      const name = pdfNameToString(key);
      if (name && name.toLowerCase() !== 'off') return name;
    }
  } catch {}
  return '';
}

function radioValue(field, PDFName){
  try { const selected=field.getSelected?.(); if (selected) return pdfNameToString(selected); } catch {}
  try { return pdfNameToString(field?.acroField?.dict?.lookup?.(PDFName.of('V'))); } catch { return ''; }
}

function radioOptions(field, PDFName){
  try {
    const options = field.getOptions?.();
    if (Array.isArray(options) && options.length) return options.map(pdfNameToString);
  } catch {}
  try {
    const raw = field?.acroField?.dict?.lookup?.(PDFName.of('Opt'));
    const array = raw?.asArray?.() || raw;
    return Array.isArray(array) ? array.map(pdfNameToString).filter(Boolean) : [];
  } catch { return []; }
}

function applyRadioCrosses(pdfDoc, form, options, PDFLib){
  const {PDFName} = PDFLib;
  for (const field of form.getFields()) {
    if (!(field instanceof PDFLib.PDFRadioGroup)) continue;
    const selected = radioValue(field,PDFName);
    const widgets = field?.acroField?.getWidgets?.() || [];
    const values = radioOptions(field,PDFName);
    widgets.forEach((widget,index) => {
      let onName = widgetOnName(widget,PDFName) || values[index] || selected;
      if (!onName) return;
      onName = pdfNameToString(onName);
      const active = selected && selected.toLowerCase() === onName.toLowerCase();
      const rect = widget.getRectangle();
      const on = appearanceStream(pdfDoc,rect,crossColor(widget,field,options,PDFName),true);
      const off = appearanceStream(pdfDoc,rect,null,false);
      const normal = pdfDoc.context.obj({Off:off,[onName]:on});
      widget.dict.set(PDFName.of('AP'),pdfDoc.context.obj({N:normal}));
      widget.dict.set(PDFName.of('AS'),PDFName.of(active ? onName : 'Off'));
    });
    if (selected) {
      try {
        field.acroField?.setValue?.(PDFName.of(selected));
        field.acroField?.dict?.set?.(PDFName.of('V'),PDFName.of(selected));
        field.acroField?.dict?.set?.(PDFName.of('DV'),PDFName.of(selected));
      } catch {}
    }
  }
}

function fillForm(pdfDoc, values, fieldMeta, language, PDFLib){
  const form = pdfDoc.getForm();
  for (const field of form.getFields()) {
    const name = field.getName();
    if (!Object.prototype.hasOwnProperty.call(values,name)) continue;
    const meta = fieldMeta?.[name] || {};
    let value = String(values[name] ?? '');
    if (String(meta.field_type || '').toLowerCase() === 'date') value = normalizeDate(value,meta.date_format,language);
    try {
      if (typeof field.setText === 'function') field.setText(value);
      else if (typeof field.check === 'function') {
        if (['1','ja','yes','true','x','on'].includes(value.trim().toLowerCase())) field.check();
        else field.uncheck?.();
      } else if (typeof field.select === 'function' && value !== '') field.select(value);
    } catch {}
  }
  try {
    const key = PDFLib.PDFName.of('NeedAppearances');
    form.acroForm?.dict?.delete?.(key);
    if (PDFLib.PDFBool) form.acroForm?.dict?.set?.(key,PDFLib.PDFBool.False);
  } catch {}
  return form;
}

function signatureWidgetPage(pdfDoc, widget, PDFLib){
  try {
    const direct = widget?.getPage?.();
    if (direct) return direct;
  } catch {}
  const pages = pdfDoc.getPages();
  try {
    const pageRef = widget?.P?.();
    if (pageRef) {
      const match = pages.find((page) => String(page.ref) === String(pageRef));
      if (match) return match;
    }
  } catch {}
  try {
    const widgetRef = widget?.ref;
    if (widgetRef) {
      for (const page of pages) {
        const annots = page?.node?.Annots?.();
        const refs = annots?.asArray?.() || [];
        if (refs.some((ref) => String(ref) === String(widgetRef))) return page;
      }
    }
  } catch {}
  return pages.length === 1 ? pages[0] : null;
}

async function drawSignaturePlaceholders(pdfDoc, form, values, fieldMeta, PDFLib){
  let font = null;
  try { font = await pdfDoc.embedFont(PDFLib.StandardFonts.HelveticaOblique); } catch {}
  for (const field of form.getFields()) {
    const name = field.getName();
    const meta = fieldMeta?.[name] || {};
    if (String(meta.field_type || '').toLowerCase() !== 'signature') continue;
    if (typeof field.setText === 'function') continue;
    const value = String(values?.[name] ?? '').trim();
    if (!value) continue;
    for (const widget of field?.acroField?.getWidgets?.() || []) {
      const rect = widget?.getRectangle?.();
      const page = signatureWidgetPage(pdfDoc,widget,PDFLib);
      if (!rect || !page || !rect.width || !rect.height) continue;
      const size = Math.max(7,Math.min(12,rect.height*.48));
      let shown = value;
      if (font) {
        const maxWidth = Math.max(1,rect.width-6);
        while (shown.length > 1 && font.widthOfTextAtSize(shown,size) > maxWidth) shown = shown.slice(0,-1);
      }
      try {
        page.drawText(shown,{
          x:rect.x+3,
          y:rect.y+Math.max(2,(rect.height-size)*.48),
          size,
          font:font || undefined,
          color:PDFLib.rgb(.12,.18,.32),
          maxWidth:Math.max(1,rect.width-6),
        });
      } catch {}
    }
  }
}

export async function generateFilledReportPdf(options){
  const config = {
    pdfLibUrl: 'https://unpkg.com/pdf-lib@1.17.1/dist/pdf-lib.min.js',
    fontkitUrl: 'https://unpkg.com/@pdf-lib/fontkit@1.1.1/dist/fontkit.umd.min.js',
    language: 'de',
    radioCrossColorMode: 'pdf_text',
    radioCrossColorStudentHex: '#0B57D0',
    radioCrossColorTeacherHex: '#0B57D0',
    fieldValues: {},
    fieldMeta: {},
    ...options,
  };
  const PDFLib = await loadPdfLib(config.pdfLibUrl);
  const response = await fetch(config.templateUrl,{credentials:'same-origin'});
  if (!response.ok) throw new Error(`Template-Download fehlgeschlagen (HTTP ${response.status})`);
  const pdfDoc = await PDFLib.PDFDocument.load(new Uint8Array(await response.arrayBuffer()));
  const form = fillForm(pdfDoc,config.fieldValues,config.fieldMeta,config.language,PDFLib);
  await updateTextAppearances(form,pdfDoc,config,PDFLib);
  applyRadioCrosses(pdfDoc,form,config,PDFLib);
  await drawSignaturePlaceholders(pdfDoc,form,config.fieldValues,config.fieldMeta,PDFLib);
  return new Uint8Array(await pdfDoc.save());
}

export async function flattenReportPdf(bytes, pdfLibUrl = 'https://unpkg.com/pdf-lib@1.17.1/dist/pdf-lib.min.js'){
  const PDFLib = await loadPdfLib(pdfLibUrl);
  const pdfDoc = await PDFLib.PDFDocument.load(bytes);
  try {
    const form = pdfDoc.getForm();
    try { form.updateFieldAppearances(); } catch {}
    try { form.flatten(); } catch {}
  } catch {}
  return new Uint8Array(await pdfDoc.save());
}

function pageHasContents(page, PDFLib){
  try {
    const contents = page?.node?.get?.(PDFLib.PDFName.of('Contents'));
    if (!contents) return false;
    if (PDFLib.PDFArray && contents instanceof PDFLib.PDFArray && contents.size() === 0) return false;
  } catch { return true; }
  return true;
}

export async function createReportBookletPdf(bytes, pdfLibUrl = 'https://unpkg.com/pdf-lib@1.17.1/dist/pdf-lib.min.js'){
  const PDFLib = await loadPdfLib(pdfLibUrl);
  const source = await PDFLib.PDFDocument.load(bytes);
  const pageCount = source.getPageCount();
  if (!pageCount) return new Uint8Array(bytes);

  const firstSize = source.getPage(0).getSize();
  const width = firstSize.width;
  const height = firstSize.height;
  const total = pageCount + ((4 - (pageCount % 4)) % 4);
  const sheets = total / 4;
  const output = await PDFLib.PDFDocument.create();

  const embed = async (index) => {
    if (index >= pageCount) return null;
    const page = source.getPage(index);
    if (!pageHasContents(page,PDFLib)) return null;
    try { return await output.embedPage(page); }
    catch (error) {
      const message = String(error?.message || error || '').toLowerCase();
      if (message.includes('missing contents') || message.includes('missing /contents')) return null;
      throw error;
    }
  };
  const draw = (target, page, x) => {
    if (page) target.drawPage(page,{x,y:0,width,height});
  };

  for (let sheet=0; sheet<sheets; sheet++) {
    const rightIndex = sheet*2;
    const leftIndex = total-1-(sheet*2);
    const backLeftIndex = sheet*2+1;
    const backRightIndex = total-2-(sheet*2);
    const front = output.addPage([width*2,height]);
    draw(front,await embed(leftIndex),0);
    draw(front,await embed(rightIndex),width);
    const back = output.addPage([width*2,height]);
    draw(back,await embed(backLeftIndex),0);
    draw(back,await embed(backRightIndex),width);
  }
  return new Uint8Array(await output.save());
}
