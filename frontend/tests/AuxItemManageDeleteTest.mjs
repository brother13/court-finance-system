import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import assert from 'assert'

const __dirname = dirname(fileURLToPath(import.meta.url))
const component = readFileSync(resolve(__dirname, '../src/views/base/AuxItemManageView.vue'), 'utf8')
const api = readFileSync(resolve(__dirname, '../src/api/base.ts'), 'utf8')

assert(component.includes('@click.stop="confirmTypeDelete(item)"'), 'custom dimension list should include a delete action')
assert(component.includes('await baseApi.deleteAuxType'), 'dimension delete should call the delete API wrapper')
assert(component.includes('await baseApi.deleteAuxArchive'), 'archive delete should call the delete API wrapper')
assert(component.includes('ElMessageBox.confirm'), 'auxiliary deletes should ask for confirmation')
assert(component.includes("v-permission=\"'base:delete'\""), 'delete actions should require base:delete permission')
assert(api.includes('deleteAuxType(auxTypeId: string)'), 'base API should expose deleteAuxType(auxTypeId)')
assert(api.includes("apiAction('/aux/typeDel'"), 'deleteAuxType should call /aux/typeDel')
assert(api.includes('deleteAuxArchive(archiveId: string)'), 'base API should expose deleteAuxArchive(archiveId)')
assert(api.includes("apiAction('/aux/archiveDel'"), 'deleteAuxArchive should call /aux/archiveDel')

console.log('Aux item manage delete test passed')
