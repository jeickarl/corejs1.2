import { ApiProperty } from '@nestjs/swagger';
import { SuppliersReportRowDto } from './suppliersReportRow.dto';

export class SuppliersReportDto {
  @ApiProperty({ type: [SuppliersReportRowDto] })
  items!: SuppliersReportRowDto[];
}

