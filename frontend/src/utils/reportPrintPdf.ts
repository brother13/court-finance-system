export type ReportPrintColumn = {
  key: string
  label: string
  width: number
  align?: 'left' | 'center' | 'right'
}

export type ReportPrintPayload = {
  title: string
  subtitle: string
  columns: ReportPrintColumn[]
  rows: Record<string, string | number>[]
  totals?: Record<string, string | number>
}

const MM_TO_PX = 96 / 25.4
const PT_PER_MM = 72 / 25.4
const PAGE = { width: 297, height: 210 }
const MARGIN = 14
const HEADER_HEIGHT = 30
const ROW_HEIGHT = 9
const FOOTER_HEIGHT = 10

const escapeXml = (value: string | number | undefined) =>
  String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')

const text = (content: string | number, x: number, y: number, options: { size?: number; anchor?: string; weight?: number } = {}) =>
  `<text x="${x}" y="${y}" font-size="${options.size || 10}" font-weight="${options.weight || 400}" text-anchor="${options.anchor || 'start'}">${escapeXml(content)}</text>`

const fitText = (value: string | number, maxChars: number) => {
  const chars = Array.from(String(value ?? ''))
  return chars.length > maxChars ? chars.slice(0, Math.max(0, maxChars - 1)).join('') + '…' : chars.join('')
}

const cellText = (value: string | number, x: number, y: number, width: number, align: ReportPrintColumn['align']) => {
  const anchor = align === 'right' ? 'end' : align === 'center' ? 'middle' : 'start'
  const textX = align === 'right' ? x + width - 3 : align === 'center' ? x + width / 2 : x + 3
  return text(fitText(value, Math.max(4, Math.floor(width / 3))), textX, y, { size: 8.5, anchor })
}

const paginateRows = (rows: Record<string, string | number>[]) => {
  const rowsPerPage = Math.max(1, Math.floor((PAGE.height - MARGIN * 2 - HEADER_HEIGHT - FOOTER_HEIGHT - ROW_HEIGHT * 2) / ROW_HEIGHT))
  const pages: Record<string, string | number>[][] = []
  for (let index = 0; index < rows.length; index += rowsPerPage) {
    pages.push(rows.slice(index, index + rowsPerPage))
  }
  return pages.length > 0 ? pages : [[]]
}

const renderPageSvg = (payload: ReportPrintPayload, pageRows: Record<string, string | number>[], pageNo: number, pageCount: number, includeTotals: boolean) => {
  const widthPx = Math.round(PAGE.width * MM_TO_PX)
  const heightPx = Math.round(PAGE.height * MM_TO_PX)
  const scale = widthPx / PAGE.width
  const toPx = (mm: number) => Math.round(mm * scale)
  const tableX = toPx(MARGIN)
  const tableY = toPx(MARGIN + HEADER_HEIGHT)
  const tableW = toPx(PAGE.width - MARGIN * 2)
  const rowH = toPx(ROW_HEIGHT)
  const colTotal = payload.columns.reduce((sum, column) => sum + column.width, 0)
  let cursorX = tableX
  const colXs = payload.columns.map((column) => {
    const width = Math.round(tableW * column.width / colTotal)
    const info = { x: cursorX, width }
    cursorX += width
    return info
  })
  colXs[colXs.length - 1].width = tableX + tableW - colXs[colXs.length - 1].x
  const totalRows = pageRows.length + 1 + (includeTotals && payload.totals ? 1 : 0)
  const tableH = rowH * totalRows
  const lines: string[] = [
    `<svg xmlns="http://www.w3.org/2000/svg" width="${widthPx}" height="${heightPx}" viewBox="0 0 ${widthPx} ${heightPx}">`,
    '<rect width="100%" height="100%" fill="#fff"/>',
    '<g font-family="Songti SC, SimSun, Noto Serif CJK SC, serif" fill="#111">',
    text(payload.title, widthPx / 2, toPx(MARGIN + 8), { size: 24, anchor: 'middle', weight: 700 }),
    text(payload.subtitle, tableX, toPx(MARGIN + 19), { size: 10 }),
    text(`第 ${pageNo} / ${pageCount} 页`, tableX + tableW, toPx(MARGIN + 19), { size: 10, anchor: 'end' }),
    `<rect x="${tableX}" y="${tableY}" width="${tableW}" height="${tableH}" fill="none" stroke="#111" stroke-width="1"/>`,
  ]
  payload.columns.forEach((column, index) => {
    const col = colXs[index]
    if (index > 0) lines.push(`<line x1="${col.x}" y1="${tableY}" x2="${col.x}" y2="${tableY + tableH}" stroke="#111" stroke-width="0.8"/>`)
    lines.push(cellText(column.label, col.x, tableY + Math.round(rowH * 0.68), col.width, 'center'))
  })
  lines.push(`<line x1="${tableX}" y1="${tableY + rowH}" x2="${tableX + tableW}" y2="${tableY + rowH}" stroke="#111" stroke-width="0.8"/>`)
  pageRows.forEach((row, rowIndex) => {
    const y = tableY + rowH * (rowIndex + 1)
    lines.push(`<line x1="${tableX}" y1="${y + rowH}" x2="${tableX + tableW}" y2="${y + rowH}" stroke="#111" stroke-width="0.5"/>`)
    payload.columns.forEach((column, index) => {
      const col = colXs[index]
      lines.push(cellText(row[column.key] ?? '', col.x, y + Math.round(rowH * 0.68), col.width, column.align || 'left'))
    })
  })
  if (includeTotals && payload.totals) {
    const y = tableY + rowH * (pageRows.length + 1)
    lines.push(`<rect x="${tableX}" y="${y}" width="${tableW}" height="${rowH}" fill="#f5f5f5"/>`)
    payload.columns.forEach((column, index) => {
      const col = colXs[index]
      lines.push(cellText(payload.totals?.[column.key] ?? '', col.x, y + Math.round(rowH * 0.68), col.width, column.align || 'left'))
    })
  }
  lines.push('</g></svg>')
  return { svg: lines.join(''), widthPx, heightPx }
}

const svgToJpeg = async (svg: string, width: number, height: number) => {
  const image = new Image()
  const svgBlob = new Blob([svg], { type: 'image/svg+xml;charset=utf-8' })
  const url = URL.createObjectURL(svgBlob)
  try {
    await new Promise<void>((resolve, reject) => {
      image.onload = () => resolve()
      image.onerror = () => reject(new Error('报表页面渲染失败'))
      image.src = url
    })
    const canvas = document.createElement('canvas')
    canvas.width = width
    canvas.height = height
    const context = canvas.getContext('2d')
    if (!context) throw new Error('浏览器不支持 Canvas PDF 渲染')
    context.fillStyle = '#fff'
    context.fillRect(0, 0, width, height)
    context.drawImage(image, 0, 0, width, height)
    return canvas.toDataURL('image/jpeg', 0.92)
  } finally {
    URL.revokeObjectURL(url)
  }
}

const textEncoder = new TextEncoder()
const ascii = (value: string) => textEncoder.encode(value)
const base64ToBytes = (value: string) => Uint8Array.from(atob(value), (char) => char.charCodeAt(0))

const concatBytes = (chunks: Uint8Array[]) => {
  const total = chunks.reduce((sum, chunk) => sum + chunk.length, 0)
  const result = new Uint8Array(total)
  let offset = 0
  chunks.forEach((chunk) => {
    result.set(chunk, offset)
    offset += chunk.length
  })
  return result
}

const buildPdf = (images: { dataUrl: string; widthPx: number; heightPx: number }[]) => {
  const pageWidthPt = PAGE.width * PT_PER_MM
  const pageHeightPt = PAGE.height * PT_PER_MM
  const chunks: Uint8Array[] = []
  const offsets: number[] = [0]
  let byteOffset = 0
  const add = (chunk: Uint8Array) => {
    chunks.push(chunk)
    byteOffset += chunk.length
  }
  const addText = (value: string) => add(ascii(value))
  const addObject = (id: number, body: Uint8Array | string, beforeStream = '', afterStream = '') => {
    offsets[id] = byteOffset
    addText(`${id} 0 obj\n`)
    if (typeof body === 'string') {
      addText(body)
    } else {
      addText(beforeStream)
      add(body)
      addText(afterStream)
    }
    addText('\nendobj\n')
  }
  addText('%PDF-1.4\n%\xE2\xE3\xCF\xD3\n')
  const pageObjectIds: number[] = []
  let nextId = 3
  images.forEach((image, index) => {
    const imageId = nextId++
    const contentId = nextId++
    const pageId = nextId++
    pageObjectIds.push(pageId)
    const imageBytes = base64ToBytes(image.dataUrl.split(',')[1] || '')
    addObject(imageId, imageBytes, `<< /Type /XObject /Subtype /Image /Width ${image.widthPx} /Height ${image.heightPx} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ${imageBytes.length} >>\nstream\n`, '\nendstream')
    const commands = `q\n${pageWidthPt.toFixed(2)} 0 0 ${pageHeightPt.toFixed(2)} 0 0 cm\n/Im${index} Do\nQ`
    addObject(contentId, `<< /Length ${commands.length} >>\nstream\n${commands}\nendstream`)
    addObject(pageId, `<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ${pageWidthPt.toFixed(2)} ${pageHeightPt.toFixed(2)}] /Resources << /XObject << /Im${index} ${imageId} 0 R >> >> /Contents ${contentId} 0 R >>`)
  })
  addObject(1, '<< /Type /Catalog /Pages 2 0 R >>')
  addObject(2, `<< /Type /Pages /Kids [${pageObjectIds.map((id) => `${id} 0 R`).join(' ')}] /Count ${pageObjectIds.length} >>`)
  const xrefOffset = byteOffset
  addText(`xref\n0 ${offsets.length}\n0000000000 65535 f \n`)
  for (let id = 1; id < offsets.length; id += 1) {
    addText(`${String(offsets[id]).padStart(10, '0')} 00000 n \n`)
  }
  addText(`trailer\n<< /Size ${offsets.length} /Root 1 0 R >>\nstartxref\n${xrefOffset}\n%%EOF`)
  return new Blob([concatBytes(chunks)], { type: 'application/pdf' })
}

export const createReportPrintPdf = async (payload: ReportPrintPayload) => {
  const pages = paginateRows(payload.rows)
  const images = []
  for (let index = 0; index < pages.length; index += 1) {
    const { svg, widthPx, heightPx } = renderPageSvg(payload, pages[index], index + 1, pages.length, index === pages.length - 1)
    images.push({ dataUrl: await svgToJpeg(svg, widthPx, heightPx), widthPx, heightPx })
  }
  return buildPdf(images)
}
