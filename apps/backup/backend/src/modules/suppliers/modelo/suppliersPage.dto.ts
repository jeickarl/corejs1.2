import { ApiProperty } from '@nestjs/swagger';
import { SupplierDto } from './supplier.dto';

export class SuppliersPageDto {
  @ApiProperty({ type: [SupplierDto] })
  items!: SupplierDto[];

  @ApiProperty({ example: 1 })
  page!: number;

  @ApiProperty({ example: 10 })
  perPage!: number;

  @ApiProperty({ example: 0 })
  total!: number;
}

