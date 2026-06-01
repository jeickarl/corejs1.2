import { ApiProperty } from '@nestjs/swagger';

export class SupplierDto {
  @ApiProperty({ example: 1 })
  id!: number;

  @ApiProperty({ example: 'PRV-001' })
  supplierCode!: string;

  @ApiProperty({ example: 'company' })
  supplierType!: string;

  @ApiProperty({ example: 'Proveedor SAS' })
  companyName!: string;

  @ApiProperty({ example: 'Contacto' })
  contactName!: string;

  @ApiProperty({ example: '9001234567' })
  taxId!: string;

  @ApiProperty({ example: '3000000000' })
  phone!: string;

  @ApiProperty({ example: '3000000000' })
  mobile!: string;

  @ApiProperty({ example: 'proveedor@correo.com' })
  email!: string;

  @ApiProperty({ example: 'https://proveedor.com' })
  website!: string;

  @ApiProperty({ example: 'Calle 1 #2-3' })
  address!: string;

  @ApiProperty({ example: 'Bogotá' })
  city!: string;

  @ApiProperty({ example: 'Cundinamarca' })
  state!: string;

  @ApiProperty({ example: 'CO' })
  country!: string;

  @ApiProperty({ example: '110111' })
  postalCode!: string;

  @ApiProperty({ example: '30 días' })
  paymentTerms!: string;

  @ApiProperty({ example: 0 })
  creditLimit!: number | null;

  @ApiProperty({ example: 0 })
  discountPercentage!: number | null;

  @ApiProperty({ example: 'Banco' })
  bankName!: string;

  @ApiProperty({ example: '123-456' })
  accountNumber!: string;

  @ApiProperty({ example: 'Ahorros' })
  accountType!: string;

  @ApiProperty({ example: true })
  isActive!: boolean;

  @ApiProperty({ example: 5 })
  rating!: number | null;

  @ApiProperty({ example: 'Notas internas' })
  notes!: string;

  @ApiProperty({ example: '2026-01-01T00:00:00.000Z' })
  createdAt!: string;

  @ApiProperty({ example: '2026-01-01T00:00:00.000Z' })
  updatedAt!: string;
}

