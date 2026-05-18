type PrintableVoucher = {
  voucher_id: string
  period: string
  voucher_date: string
  voucher_word?: string
  voucher_no: number
  attachment_count?: number
  prepared_by_name?: string
  audit_by_name?: string
  posted_by_name?: string
  details: PrintableDetail[]
}

type PrintableDetail = {
  summary?: string
  subject_code?: string
  subject_name?: string
  aux_desc?: string
  debit_amount?: string | number
  credit_amount?: string | number
}

type PrintPayload = {
  account_set?: {
    set_name?: string
    finance_manager?: string
    paper_size?: string
    voucher_print_line_count?: number
  }
  paper_size?: string
  voucher_print_line_count?: number
  vouchers: PrintableVoucher[]
}

type VoucherPrintPage = {
  voucher: PrintableVoucher
  details: PrintableDetail[]
  pageNo: number
  pageCount: number
}

const MM_TO_PX = 96 / 25.4
const PT_PER_MM = 72 / 25.4

const landscapePaperSizeMm = (paperSize: string) => paperSize === 'A4'
  ? { width: 297, height: 210 }
  : { width: 210, height: 148 }

const PRINT_FONT_SIZES = {
  title: 30,
  meta: 15,
  header: 15,
  detail: 13,
  subject: 12,
  amount: 14,
  total: 15,
  footer: 15,
}

const SUMMARY_COLUMN_RATIO = 0.4
const SUBJECT_COLUMN_RATIO = 0.22
const AMOUNT_COLUMN_RATIO = 0.19
const SUMMARY_MAX_CHARS = 25
const SUBJECT_MAX_CHARS = 28

const money = (value: string | number | undefined) => {
  const amount = Number(value || 0)
  return amount === 0 ? '' : amount.toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

const cents = (value: string | number | undefined) => Math.round(Number(value || 0) * 100)
const yuan = (value: number) => (value / 100).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

const escapeXml = (value: string | number | undefined) =>
  String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')

const splitText = (value: string, maxChars: number, maxLines: number) => {
  const chars = Array.from(value || '')
  const lines: string[] = []
  for (let index = 0; index < chars.length && lines.length < maxLines; index += maxChars) {
    lines.push(chars.slice(index, index + maxChars).join(''))
  }
  if (chars.length > maxChars * maxLines && lines.length > 0) {
    lines[lines.length - 1] = lines[lines.length - 1].slice(0, Math.max(0, maxChars - 1)) + '…'
  }
  return lines.length > 0 ? lines : ['']
}

export const splitVoucherPages = (voucher: PrintableVoucher, lineCount: number): VoucherPrintPage[] => {
  const safeLineCount = Math.max(1, Number(lineCount || 8))
  const details = voucher.details || []
  const totalPages = Math.max(1, Math.ceil(details.length / safeLineCount))
  return Array.from({ length: totalPages }, (_, index) => ({
    voucher,
    details: details.slice(index * safeLineCount, (index + 1) * safeLineCount),
    pageNo: index + 1,
    pageCount: totalPages
  }))
}

const text = (content: string, x: number, y: number, options: { size?: number; anchor?: string; weight?: number } = {}) =>
  `<text x="${x}" y="${y}" font-size="${options.size || 12}" font-weight="${options.weight || 400}" text-anchor="${options.anchor || 'start'}">${escapeXml(content)}</text>`

const wrappedText = (content: string, x: number, y: number, maxChars: number, maxLines: number, lineHeight: number, size = 10) => {
  const lines = splitText(content, maxChars, maxLines)
  return `<text x="${x}" y="${y}" font-size="${size}">${lines.map((line, index) => `<tspan x="${x}" dy="${index === 0 ? 0 : lineHeight}">${escapeXml(line)}</tspan>`).join('')}</text>`
}

const renderVoucherPageSvg = (page: VoucherPrintPage, payload: PrintPayload, pageWidth: number, pageHeight: number) => {
  const margin = Math.round(pageWidth * 0.1)
  const tableX = margin
  const tableW = pageWidth - margin * 2
  const titleY = Math.round(pageHeight * 0.12)
  const metaY = titleY + 34
  const tableY = metaY + 16
  const footerY = pageHeight - Math.round(pageHeight * 0.1)
  const tableH = footerY - tableY - 24
  const lineCount = Math.max(1, Number(payload.voucher_print_line_count || payload.account_set?.voucher_print_line_count || 8))
  const rowH = Math.floor((tableH - 34) / (lineCount + 1))
  const summaryW = Math.round(tableW * SUMMARY_COLUMN_RATIO)
  const subjectW = Math.round(tableW * SUBJECT_COLUMN_RATIO)
  const debitW = Math.round(tableW * AMOUNT_COLUMN_RATIO)
  const creditW = tableW - summaryW - subjectW - debitW
  const x1 = tableX + summaryW
  const x2 = x1 + subjectW
  const x3 = x2 + debitW
  const headerH = 34
  const rows = [...page.details]
  while (rows.length < lineCount) rows.push({})
  const debitTotal = page.details.reduce((sum, detail) => sum + cents(detail.debit_amount), 0)
  const creditTotal = page.details.reduce((sum, detail) => sum + cents(detail.credit_amount), 0)
  const voucher = page.voucher
  const voucherNo = `${voucher.voucher_word || '记'}-${voucher.voucher_no}(${page.pageNo}/${page.pageCount})`

  const lines: string[] = [
    `<svg xmlns="http://www.w3.org/2000/svg" width="${pageWidth}" height="${pageHeight}" viewBox="0 0 ${pageWidth} ${pageHeight}">`,
    '<rect width="100%" height="100%" fill="#fff"/>',
    `<g font-family="Songti SC, SimSun, Noto Serif CJK SC, serif" fill="#111">`,
    text('记账凭证', pageWidth / 2, titleY, { size: PRINT_FONT_SIZES.title, anchor: 'middle', weight: 700 }),
    text(`账套：${payload.account_set?.set_name || ''}`, tableX, metaY, { size: PRINT_FONT_SIZES.meta }),
    text(`日期：${voucher.voucher_date || ''}`, pageWidth / 2, metaY, { size: PRINT_FONT_SIZES.meta, anchor: 'middle' }),
    text(`附单据：${voucher.attachment_count || 0}`, x3 - 50, metaY, { size: PRINT_FONT_SIZES.meta }),
    text(`凭证号：${voucherNo}`, tableX + tableW, metaY, { size: PRINT_FONT_SIZES.meta, anchor: 'end' }),
    `<rect x="${tableX}" y="${tableY}" width="${tableW}" height="${tableH}" fill="none" stroke="#111" stroke-width="1"/>`,
    `<line x1="${x1}" y1="${tableY}" x2="${x1}" y2="${tableY + tableH}" stroke="#111" stroke-width="1"/>`,
    `<line x1="${x2}" y1="${tableY}" x2="${x2}" y2="${tableY + tableH}" stroke="#111" stroke-width="1"/>`,
    `<line x1="${x3}" y1="${tableY}" x2="${x3}" y2="${tableY + tableH}" stroke="#111" stroke-width="1"/>`,
    `<line x1="${tableX}" y1="${tableY + headerH}" x2="${tableX + tableW}" y2="${tableY + headerH}" stroke="#111" stroke-width="1"/>`,
    text('摘要', tableX + summaryW / 2, tableY + 23, { size: PRINT_FONT_SIZES.header, anchor: 'middle' }),
    text('科目', x1 + subjectW / 2, tableY + 23, { size: PRINT_FONT_SIZES.header, anchor: 'middle' }),
    text('借方金额', x2 + debitW / 2, tableY + 23, { size: PRINT_FONT_SIZES.header, anchor: 'middle' }),
    text('贷方金额', x3 + creditW / 2, tableY + 23, { size: PRINT_FONT_SIZES.header, anchor: 'middle' }),
  ]

  rows.forEach((detail, index) => {
    const y = tableY + headerH + index * rowH
    lines.push(`<line x1="${tableX}" y1="${y + rowH}" x2="${tableX + tableW}" y2="${y + rowH}" stroke="#111" stroke-width="0.8"/>`)
    lines.push(wrappedText(detail.summary || '', tableX + 8, y + 19, SUMMARY_MAX_CHARS, 2, 15, PRINT_FONT_SIZES.detail))
    lines.push(wrappedText(`${detail.subject_code || ''} ${detail.subject_name || ''}${detail.aux_desc ? ' ' + detail.aux_desc : ''}`.trim(), x1 + 8, y + 18, SUBJECT_MAX_CHARS, 2, 14, PRINT_FONT_SIZES.subject))
    lines.push(text(money(detail.debit_amount), x3 - 8, y + 23, { size: PRINT_FONT_SIZES.amount, anchor: 'end' }))
    lines.push(text(money(detail.credit_amount), tableX + tableW - 8, y + 23, { size: PRINT_FONT_SIZES.amount, anchor: 'end' }))
  })

  const totalY = tableY + headerH + lineCount * rowH
  lines.push(text('合计：', tableX + 8, totalY + 23, { size: PRINT_FONT_SIZES.total, weight: 700 }))
  lines.push(text(yuan(debitTotal), x3 - 8, totalY + 23, { size: PRINT_FONT_SIZES.total, anchor: 'end', weight: 700 }))
  lines.push(text(yuan(creditTotal), tableX + tableW - 8, totalY + 23, { size: PRINT_FONT_SIZES.total, anchor: 'end', weight: 700 }))
  lines.push(text(`会计主管：${payload.account_set?.finance_manager || ''}`, tableX + tableW * 0.36, footerY, { size: PRINT_FONT_SIZES.footer }))
  lines.push(text(`审核人：${voucher.audit_by_name || voucher.posted_by_name || ''}`, tableX + tableW * 0.62, footerY, { size: PRINT_FONT_SIZES.footer }))
  lines.push(text(`制单人：${voucher.prepared_by_name || ''}`, tableX + tableW, footerY, { size: PRINT_FONT_SIZES.footer, anchor: 'end' }))
  lines.push('</g></svg>')
  return lines.join('')
}

const svgToJpeg = async (svg: string, width: number, height: number) => {
  const image = new Image()
  const svgBlob = new Blob([svg], { type: 'image/svg+xml;charset=utf-8' })
  const url = URL.createObjectURL(svgBlob)
  try {
    await new Promise<void>((resolve, reject) => {
      image.onload = () => resolve()
      image.onerror = () => reject(new Error('凭证页面渲染失败'))
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

const buildPdf = (images: { dataUrl: string; widthPx: number; heightPx: number }[], pageWidthPt: number, pageHeightPt: number) => {
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
    const base64 = image.dataUrl.split(',')[1] || ''
    const imageBytes = base64ToBytes(base64)
    addObject(
      imageId,
      imageBytes,
      `<< /Type /XObject /Subtype /Image /Width ${image.widthPx} /Height ${image.heightPx} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ${imageBytes.length} >>\nstream\n`,
      '\nendstream'
    )
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

export const createVoucherPrintPdf = async (payload: PrintPayload) => {
  const paper_size = payload.paper_size || payload.account_set?.paper_size || 'A5'
  const voucher_print_line_count = Number(payload.voucher_print_line_count || payload.account_set?.voucher_print_line_count || 8)
  const paper = landscapePaperSizeMm(paper_size)
  const widthPx = Math.round(paper.width * MM_TO_PX)
  const heightPx = Math.round(paper.height * MM_TO_PX)
  const pageWidthPt = paper.width * PT_PER_MM
  const pageHeightPt = paper.height * PT_PER_MM
  const pages = payload.vouchers.flatMap((voucher) => splitVoucherPages(voucher, voucher_print_line_count))
  const images = []
  for (const page of pages) {
    const svg = renderVoucherPageSvg(page, { ...payload, paper_size, voucher_print_line_count }, widthPx, heightPx)
    images.push({ dataUrl: await svgToJpeg(svg, widthPx, heightPx), widthPx, heightPx })
  }
  return buildPdf(images, pageWidthPt, pageHeightPt)
}
