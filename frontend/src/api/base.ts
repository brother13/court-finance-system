import { apiAction } from './http'
import type { Subject, SubjectCodeRule } from '../types/api'

export const baseApi = {
  subjects() {
    return apiAction('/subject/list') as Promise<Subject[]>
  },
  subjectCodeRule() {
    return apiAction('/subject/codeRule') as Promise<SubjectCodeRule>
  },
  saveSubjectCodeRule(rule: string) {
    return apiAction('/subject/codeRuleSave', { rule }) as Promise<SubjectCodeRule>
  },
  saveSubject(payload: any) {
    return apiAction('/subject/save', payload) as Promise<string>
  },
  deleteSubject(subjectId: string) {
    return apiAction('/subject/del', { subject_id: subjectId }) as Promise<string>
  },
  importSubjects(filename: string, contentBase64: string) {
    return apiAction('/subject/import', { filename, content_base64: contentBase64 }) as Promise<{ total: number; created: number; updated: number }>
  },
  exportSubjects() {
    return apiAction('/subject/export') as Promise<{ filename: string; mime: string; content_base64: string }>
  },
  createSubject(payload: Subject) {
    return apiAction('/subject/add', {
      subject_code: payload.subjectCode,
      subject_name: payload.subjectName,
      parent_code: payload.parentCode,
      direction: payload.direction,
      subject_type: payload.subjectType,
      level_no: payload.levelNo,
      leaf_flag: payload.leafFlag
    }) as Promise<string>
  },
  auxArchives(auxTypeCode?: string, keyword?: string) {
    return apiAction('/aux/archiveList', { aux_type_code: auxTypeCode, keyword }) as Promise<any[]>
  },
  auxTypes() {
    return apiAction('/aux/typeList') as Promise<any[]>
  },
  saveAuxType(payload: any) {
    return apiAction(payload.aux_type_id ? '/aux/typeSave' : '/aux/typeAdd', payload) as Promise<string>
  },
  deleteAuxType(auxTypeId: string) {
    return apiAction('/aux/typeDel', { aux_type_id: auxTypeId }) as Promise<string>
  },
  saveAuxArchive(payload: any) {
    return apiAction(payload.archive_id ? '/aux/archiveSave' : '/aux/archiveAdd', payload) as Promise<string>
  },
  deleteAuxArchive(archiveId: string) {
    return apiAction('/aux/archiveDel', { archive_id: archiveId }) as Promise<string>
  },
  subjectConfig(subjectCode: string) {
    return apiAction('/aux/subjectConfig', { subject_code: subjectCode }) as Promise<any[]>
  },
  saveSubjectConfig(subjectCode: string, items: any[]) {
    return apiAction('/aux/subjectConfigSave', { subject_code: subjectCode, items }) as Promise<string>
  },
  openingBalances(period: string) {
    return apiAction('/opening/list', { period }) as Promise<any[]>
  },
  saveOpeningBalances(period: string, items: any[]) {
    return apiAction('/opening/save', { period, items }) as Promise<{ saved: number }>
  },
  auxOpeningBalances(period: string, subjectCode: string) {
    return apiAction('/opening/auxList', { period, subject_code: subjectCode }) as Promise<any>
  },
  saveAuxOpeningBalances(period: string, subjectCode: string, items: any[]) {
    return apiAction('/opening/auxSave', { period, subject_code: subjectCode, items }) as Promise<{ saved: number }>
  }
}
