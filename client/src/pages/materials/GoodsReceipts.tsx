import { useCrud } from "../../hooks/useCrud";
import DataTable from "../../components/DataTable";
import PageHeader from "../../components/PageHeader";

export default function GoodsReceipts() {
  const { data, pagination, isLoading, errorMessage, setPage, setSearch } = useCrud({
    key: "goods-receipts",
    endpoint: "/materials/goods-receipts",
  });

  return (
    <div>
      <PageHeader
        title="Goods Receipts"
        subtitle="Track received materials against purchase orders"
        breadcrumb={[{ label: "Materials Management" }, { label: "Goods Receipts" }]}
      />

      <DataTable
        columns={[
          { key: "grNumber", label: "GR #", render: (r: any) => <span className="font-mono text-sm">{r.grNumber}</span> },
          { key: "poNumber", label: "PO #", render: (r: any) => <span className="font-mono text-sm text-primary-700">{r.poNumber}</span> },
          { key: "receiptDate", label: "Receipt Date", render: (r: any) => new Date(r.receiptDate).toLocaleDateString() },
          { key: "itemCount", label: "Items", render: (r: any) => r.itemCount ?? 0 },
          { key: "createdAt", label: "Created", render: (r: any) => new Date(r.createdAt).toLocaleString() },
        ]}
        data={data}
        pagination={pagination}
        isLoading={isLoading}
        error={errorMessage}
        onPageChange={setPage}
        onSearch={setSearch}
        searchPlaceholder="Search by GR or PO number..."
      />
    </div>
  );
}
