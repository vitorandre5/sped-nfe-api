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
  certificate_expires_at?: string | null
  certificate_uploaded_at?: string | null
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
  municipal_tax: MunicipalTax | null
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

export type MunicipalTax = {
  status: 'available' | 'not_subject' | 'not_informed'
  tax_type: string | null
  incidence_municipality_code: string | null
  incidence_municipality: string | null
  municipal_service_code: string | null
  municipal_service_description: string | null
  special_regime: string | null
  immunity_type: string | null
  suspension_type: string | null
  suspension_process: string | null
  benefit_type: string | null
  benefit_amount: string | null
  deductions: string | null
  unconditional_discount: string | null
  tax_base: string | null
  rate: string | null
  retention_type: string | null
  amount: string | null
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
