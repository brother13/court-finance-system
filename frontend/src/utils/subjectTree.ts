import type { Subject } from '../types/api'

export interface SubjectTreeOption {
  value: string
  label: string
  disabled: boolean
  children?: SubjectTreeOption[]
}

const subjectCode = (subject: Subject) => subject.subject_code || subject.subjectCode || ''
const subjectName = (subject: Subject) => subject.subject_name || subject.subjectName || ''
const parentCode = (subject: Subject) => subject.parent_code || subject.parentCode || ''

export const isSelectableVoucherSubject = (subject: Subject) => {
  return Number(subject.leaf_flag ?? subject.leafFlag) === 1
    && Number(subject.voucher_entry_flag ?? subject.voucherEntryFlag ?? 1) === 1
    && Number(subject.status ?? 1) === 1
}

export const buildSubjectTreeOptions = (subjects: Subject[]): SubjectTreeOption[] => {
  const byCode = new Map<string, SubjectTreeOption>()
  const parentByCode = new Map<string, string>()
  const roots: SubjectTreeOption[] = []

  subjects.forEach((subject) => {
    const code = subjectCode(subject)
    if (!code) return
    byCode.set(code, {
      value: code,
      label: `${code} ${subjectName(subject)}`,
      disabled: !isSelectableVoucherSubject(subject)
    })
    parentByCode.set(code, parentCode(subject))
  })

  subjects.forEach((subject) => {
    const code = subjectCode(subject)
    const option = byCode.get(code)
    if (!code || !option) return
    const parent = byCode.get(parentByCode.get(code) || '')
    if (parent) {
      if (!parent.children) parent.children = []
      parent.children.push(option)
    } else {
      roots.push(option)
    }
  })

  const sortTree = (nodes: SubjectTreeOption[]) => {
    nodes.sort((a, b) => a.value.localeCompare(b.value, 'zh-CN'))
    nodes.forEach((node) => {
      if (node.children) sortTree(node.children)
    })
  }
  sortTree(roots)
  return roots
}
