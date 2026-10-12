import { createClient } from '@supabase/supabase-js'

const url = import.meta.env.VITE_SUPABASE_URL
const anonKey = import.meta.env.VITE_SUPABASE_ANON_KEY

export const supabase = url && anonKey ? createClient(url, anonKey) : null

export type Company = {
  id: string
  organization_id: string
  cnpj: string
  name: string
  state: string
  created_at: string
  cnpj_registry_data?: Record<string, unknown>
  cnpj_registry_fetched_at?: string | null
}

export type FiscalDocument = {
  id: string
  company_id: string
  document_type: string
  external_id: string
  access_key: string | null
  nsu: string
  issuer_name: string | null
  issuer_cnpj: string | null
  recipient_name: string | null
  recipient_cnpj: string | null
  document_number: string | null
  series: string | null
  issued_at: string | null
  total_amount: number | null
  situation: string
  is_purchase: boolean
  is_sale: boolean
  source_company_ids: string[]
  operation_categories: string[]
  cfops: string[]
  operation_nature: string | null
  storage_path: string
  created_at: string
  company?: Pick<Company, 'name' | 'cnpj'>
}

export type SyncCursor = {
  id: string
  company_id: string
  document_type: string
  last_nsu: string
  max_nsu: string | null
  next_sync_at: string | null
  last_run_at: string | null
}
